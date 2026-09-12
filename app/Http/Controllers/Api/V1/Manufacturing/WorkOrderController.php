<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Manufacturing;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Manufacturing\ApproveWorkOrderQaRequest;
use App\Http\Requests\Api\V1\Manufacturing\ReplaceWorkOrderMaterialsRequest;
use App\Http\Requests\Api\V1\Manufacturing\ReplaceWorkOrderOutputsRequest;
use App\Http\Requests\Api\V1\Manufacturing\StoreWorkOrderRequest;
use App\Http\Requests\Api\V1\Manufacturing\SubmitWorkOrderQaRequest;
use App\Http\Requests\Api\V1\Manufacturing\UpdateWorkOrderRequest;
use App\Http\Resources\Api\V1\Manufacturing\WorkOrderResource;
use App\Models\Item;
use App\Models\WorkOrder;
use App\Services\IssueVoucherService;
use App\Services\WorkOrderMaterialService;
use App\Services\WorkOrderMaterialVarianceService;
use App\Services\WorkOrderService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group 20. Manufacturing orders
 *
 * أمر التصنيع — the document the factory works from.
 *
 * The lifecycle is a chain of gates, each with its own permission, and none of
 * them is a `status` you can PATCH:
 *
 *   Draft → **approve-order** (PMO manager) → Pending
 *         → **start** → InProgress
 *         → **submit-qa** (declare what was made) → QaReview
 *         → **approve-qa** (QA sign-off)
 *         → **finish-manufacturing** (needs BOTH approvals; opens the quality
 *           sheet and tells every department the product is ready)
 *         → **complete** (produces stock, consumes WIP, writes the loss record)
 *
 * Two gates are worth knowing before you build a screen. `approve-order` and
 * `start` both refuse an order with no positive planned quantity or no
 * planned dates — the plan is what variance, efficiency and loss are measured
 * against, and a zero plan silently zeroes all three. And
 * `finish-manufacturing` refuses unless the PMO approval **and** the QA
 * approval are both already on the record; `approvals.can_finish_manufacturing`
 * on the resource answers the same question, so hide the action rather than
 * let the user discover the refusal.
 *
 * Materials and products are each written with a single PUT carrying the whole
 * list. See `replaceMaterials` for why.
 */
class WorkOrderController extends ApiController
{
    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly WorkOrderMaterialService $materials,
        private readonly IssueVoucherService $issues,
        private readonly WorkOrderMaterialVarianceService $variance,
    ) {}

    /**
     * List manufacturing orders
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the order number or title. Example: WO-2026
     * @queryParam filter[status] string One or more work_order_status values, comma separated. Example: in_progress,qa_review
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[priority] string low, normal, high or urgent. Example: urgent
     * @queryParam filter[assigned_to] integer User id. Example: 9
     * @queryParam filter[planned_start_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam filter[order_approved] boolean Whether the PMO approval is on the record. Example: false
     * @queryParam sort string Allowed: wo_number, title, status, priority, planned_start_date, planned_end_date, created_at. Example: -created_at
     * @queryParam include string Allowed: project, bom. Example: project
     * @queryParam updated_after string ISO-8601 timestamp; returns only rows changed since. Example: 2026-09-01T00:00:00Z
     *
     * @response 200 scenario="Success" {"data":[{"id":31,"type":"work_order","wo_number":"WO-202609-0004","title":"Main distribution panel","status":{"value":"in_progress","label":"In Progress","color":"info"},"quantities":{"planned":"10.0000","produced":"0.0000","waste":"0.0000"},"materials_count":6}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkOrder::class);

        $orders = ApiQuery::for(
            WorkOrder::query()
                ->withCount(['materials', 'outputs', 'issueVouchers', 'qualitySheets'])
                // The planned material cost is quantity × unit cost summed
                // over the order's material lines. Reading the model's
                // accessor here would lazy-load that relation once per row —
                // 25 extra queries for one number. One subquery instead; the
                // resource prefers this when it is present (Finding #15).
                ->withSum('materials as materials_plan_value', DB::raw('quantity * unit_cost')),
            $request,
        )
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'project' => ApiQuery::exact('project_id'),
                'priority' => ApiQuery::exact('priority'),
                'assigned_to' => ApiQuery::exact('assigned_to'),
                'bom' => ApiQuery::exact('bom_id'),
                'planned_start_date' => ApiQuery::dateBetween('planned_start_date'),
                'order_approved' => fn ($query, $value) => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? $query->whereNotNull('order_approved_at')
                    : $query->whereNull('order_approved_at'),
            ])
            ->allowSearch(['wo_number', 'title'])
            ->allowSorts(['wo_number', 'title', 'status', 'priority', 'planned_start_date', 'planned_end_date', 'created_at'])
            ->allowIncludes(['project', 'bom'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->respondPaginated(WorkOrderResource::collection($orders));
    }

    /**
     * Show a manufacturing order
     *
     * Returns the order with its finished products and its material plan.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Success" {"data":{"id":31,"type":"work_order","wo_number":"WO-202609-0004","status":{"value":"in_progress","label":"In Progress","color":"info"},"approvals":{"order_approved":true,"qa_approved":false,"can_finish_manufacturing":false},"materials":[{"item_id":7,"quantity":"40.0000","unit_cost":"1000.00","line_value":"40000.00","is_manual":false}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        return $this->respond(new WorkOrderResource($this->loaded($workOrder)));
    }

    /**
     * Raise a manufacturing order
     *
     * The order starts as a **Draft** and its number is generated by the
     * server. The plan may be incomplete at this point — a positive quantity
     * and both planned dates are required by `approve-order`, not here, so the
     * technical office can open an order while the dates are still being
     * agreed.
     *
     * Sending `outputs` also sets the order's total planned quantity and its
     * primary product, both derived from the lines.
     *
     * @authenticated
     *
     * @bodyParam project_id integer optional The operation this order belongs to. Example: 4
     * @bodyParam bom_id integer optional A specific BOM to plan from. Example: 12
     * @bodyParam title string required Example: Main distribution panel
     * @bodyParam description string optional Example: Three-phase, outdoor
     * @bodyParam priority string optional low, normal, high or urgent. Defaults to normal. Example: high
     * @bodyParam planned_quantity number optional Ignored when `outputs` is sent — the sum of the lines wins. Example: 10
     * @bodyParam estimated_cost number optional Example: 250000
     * @bodyParam planned_start_date date optional Example: 2026-09-15
     * @bodyParam planned_end_date date optional Example: 2026-09-30
     * @bodyParam assigned_to integer optional Example: 9
     * @bodyParam specs object optional The technical sheet (المواصفات الفنية) copied onto the quality sheet later.
     * @bodyParam specs.conductor_type string optional Example: Copper
     * @bodyParam specs.protection_degree string optional Example: IP54
     * @bodyParam specs.poles_count integer optional Example: 4
     * @bodyParam outputs object[] optional The finished products (المنتجات التامة).
     * @bodyParam outputs[].item_id integer required Example: 21
     * @bodyParam outputs[].planned_quantity number required Example: 10
     * @bodyParam outputs[].notes string optional Example: Blue paint
     *
     * @response 201 scenario="Created" {"data":{"id":31,"type":"work_order","wo_number":"WO-202609-0004","status":{"value":"draft","label":"Draft","color":"gray"},"quantities":{"planned":"10.0000"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $this->authorize('create', WorkOrder::class);

        $order = DB::transaction(function () use ($request): WorkOrder {
            $order = WorkOrder::create(array_merge(
                [
                    'wo_number' => WorkOrder::generateWoNumber(),
                    'project_id' => $request->input('project_id'),
                    'bom_id' => $request->input('bom_id'),
                    'title' => $request->string('title')->toString(),
                    'description' => $request->input('description'),
                    'priority' => $request->input('priority', 'normal'),
                    'status' => \App\Enums\WorkOrderStatus::Draft,
                    'planned_quantity' => $request->input('planned_quantity', 0),
                    'estimated_cost' => $request->input('estimated_cost', 0),
                    'planned_start_date' => $request->input('planned_start_date'),
                    'planned_end_date' => $request->input('planned_end_date'),
                    'assigned_to' => $request->input('assigned_to'),
                    'created_by' => Auth::id(),
                ],
                $this->specColumns($request),
            ));

            // Only when the caller sent products. An order created without
            // them keeps the `planned_quantity` it was given directly, which
            // is how a legacy single-product order is raised.
            if ($request->array('outputs') !== []) {
                $this->writeOutputs($order, $request->array('outputs'));
            }

            return $order;
        });

        return $this->respondCreated(new WorkOrderResource($this->loaded($order->fresh())));
    }

    /**
     * Update a manufacturing order
     *
     * Plan fields only. The status is moved by the transition endpoints, and
     * produced/waste quantities are declared at `submit-qa` — neither can be
     * written here at all.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @bodyParam title string optional Example: Main distribution panel — rev B
     * @bodyParam priority string optional low, normal, high or urgent. Example: urgent
     * @bodyParam planned_quantity number optional Example: 12
     * @bodyParam planned_start_date date optional Example: 2026-09-16
     * @bodyParam planned_end_date date optional Example: 2026-10-02
     * @bodyParam specs object optional The technical sheet.
     *
     * @response 200 scenario="Updated" {"data":{"id":31,"type":"work_order","priority":"urgent"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('update', $workOrder);

        $payload = array_merge(
            $request->safe()->except(['specs']),
            $this->specColumns($request),
        );

        $workOrder->update($payload);

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Delete a manufacturing order
     *
     * Draft and Cancelled orders only. Once an order has been released for
     * manufacturing it may already have issue vouchers against it, stock in
     * work-in-progress and cost loaded onto the operation; deleting it would
     * leave those movements pointing at a document that no longer exists. Cancel
     * it instead — the record stays, which is what the ledger needs.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Already released" {"error":{"code":"business_rule_violated","message":"Only a draft or cancelled manufacturing order can be deleted."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('delete', $workOrder);

        if (! in_array($workOrder->status, [
            \App\Enums\WorkOrderStatus::Draft,
            \App\Enums\WorkOrderStatus::Cancelled,
        ], true)) {
            throw new DomainException(__('errors.api.work_order_not_deletable'));
        }

        $workOrder->delete();

        return $this->respondNoContent();
    }

    /**
     * Replace the finished products
     *
     * The whole list in one request. The order's total planned quantity and its
     * primary product are re-derived from the lines afterwards, so they can
     * never disagree with the sum of what the order actually makes.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @bodyParam outputs object[] required The complete list. Send `[]` to clear it.
     * @bodyParam outputs[].item_id integer required Example: 21
     * @bodyParam outputs[].planned_quantity number required Example: 10
     * @bodyParam outputs[].notes string optional Example: Blue paint
     *
     * @response 200 scenario="Replaced" {"data":{"id":31,"type":"work_order","quantities":{"planned":"10.0000"},"outputs":[{"item_id":21,"planned_quantity":"10.0000"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceOutputs(ReplaceWorkOrderOutputsRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('update', $workOrder);

        DB::transaction(fn () => $this->writeOutputs($workOrder, $request->array('outputs')));

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Replace the material plan
     *
     * خامات أمر التصنيع — the whole table in one atomic request, never merged.
     * A phone on a weak link cannot reliably sequence "add line 4, delete line
     * 2, edit line 3": one dropped request in the middle leaves the server
     * holding a plan that never existed on either side. Sending the finished
     * table is a single decision that is safe to retry, and the
     * `Idempotency-Key` makes the retry free.
     *
     * This plan is what issue vouchers are validated against, so editing it
     * changes what the warehouse may issue without approval.
     *
     * `unit_cost` falls back to the item card when omitted.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @bodyParam materials object[] required The complete list. Send `[]` to clear it.
     * @bodyParam materials[].item_id integer required Example: 7
     * @bodyParam materials[].quantity number required May be zero to keep a deliberately-excluded line on the table. Example: 40
     * @bodyParam materials[].unit_cost number optional Defaults to the item's current cost. Example: 1000
     * @bodyParam materials[].is_manual boolean optional Marks a line set by hand so re-fetching the standard recipe can leave it alone. Example: true
     * @bodyParam materials[].notes string optional Example: Substituted for the 95mm busbar
     *
     * @response 200 scenario="Replaced" {"data":{"id":31,"type":"work_order","materials":[{"item_id":7,"quantity":"40.0000","unit_cost":"1000.00","line_value":"40000.00","is_manual":true}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceMaterials(ReplaceWorkOrderMaterialsRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('update', $workOrder);

        $itemCosts = Item::query()
            ->whereIn('id', array_column($request->array('materials'), 'item_id'))
            ->pluck('unit_cost', 'id');

        $lines = array_map(fn (array $line) => [
            'item_id' => (int) $line['item_id'],
            'quantity' => (float) $line['quantity'],
            'unit_cost' => (float) ($line['unit_cost'] ?? $itemCosts[$line['item_id']] ?? 0),
            'is_manual' => (bool) ($line['is_manual'] ?? false),
            'notes' => $line['notes'] ?? null,
        ], $request->array('materials'));

        $this->materials->syncMaterials($workOrder, $lines);

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Re-fetch the standard material plan
     *
     * Expands each finished product's approved **standard BOM**, scales it by
     * that product's planned quantity, merges shared raw materials into one
     * line, and REPLACES the order's material table with the result.
     *
     * This discards manual adjustments, which is the point of the action — it
     * is "start again from the recipe". Refused when a product has no approved
     * standard BOM, naming the product, because a silently short plan would
     * under-issue the order.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Refetched" {"data":{"id":31,"type":"work_order","materials":[{"item_id":7,"quantity":"40.0000","is_manual":false}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="No standard BOM" {"error":{"code":"business_rule_violated","message":"No approved standard BOM for: Main distribution panel."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function fetchStandardMaterials(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('update', $workOrder);

        $this->materials->syncMaterials(
            $workOrder,
            $this->materials->fetchStandardMaterials($workOrder),
        );

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * What the order still needs
     *
     * Per item: what the plan requires, what previous vouchers already issued
     * (net of posted returns), and what is left. This is exactly the
     * calculation an issue voucher is validated against, so a client can show
     * the store keeper what a voucher may carry before it is written rather
     * than after it is refused.
     *
     * Drafts on other vouchers are counted as already issued. That is right
     * for a suggestion — two store keepers should not each prepare a voucher
     * for the same remaining material — and deliberately different from the
     * posting gate, where only stock that has actually left the store counts.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Success" {"data":[{"item_id":7,"item_name":"Copper busbar 120mm","required":"40.0000","previously_issued":"25.0000","remaining":"15.0000","unit_cost":"1000.00"}],"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function materialRequirement(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        $rows = $this->issues->requirementFor($workOrder, includeDrafts: true)
            ->map(fn (array $row) => [
                'item_id' => $row['item_id'],
                'item_name' => $row['item_name'],
                'required' => number_format($row['required'], 4, '.', ''),
                'previously_issued' => number_format($row['previously_issued'], 4, '.', ''),
                'remaining' => number_format($row['remaining'], 4, '.', ''),
                'unit_cost' => number_format($row['unit_cost'], 2, '.', ''),
            ])
            ->values()
            ->all();

        return $this->respondCollection($rows);
    }

    /**
     * Planned vs issued material
     *
     * فروق خامات أمر التصنيع — per item, the plan against what was actually
     * issued net of returns, in quantity and in money. The report walks every
     * voucher line of the order, so it sits on the reports rate limiter.
     *
     * A positive variance means more was consumed than planned.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Success" {"data":{"rows":[{"item_id":7,"item_name":"Copper busbar 120mm","planned":"40.0000","issued":"45.0000","returned":"2.0000","net_issued":"43.0000","variance":"3.0000","unit_cost":"1000.00","variance_value":"3000.00","variance_percentage":"7.50"}],"planned_value":"40000.00","issued_value":"43000.00","variance_value":"3000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function materialVariance(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        $report = $this->variance->for($workOrder);

        return $this->respond([
            'work_order_id' => $workOrder->id,
            'wo_number' => $workOrder->wo_number,
            'rows' => collect($report['rows'])
                ->map(fn (array $row) => [
                    'item_id' => $row['item']?->id,
                    'item_name' => $row['item']?->name,
                    'planned' => number_format($row['planned'], 4, '.', ''),
                    'issued' => number_format($row['issued'], 4, '.', ''),
                    'returned' => number_format($row['returned'], 4, '.', ''),
                    'net_issued' => number_format($row['net_issued'], 4, '.', ''),
                    'variance' => number_format($row['variance'], 4, '.', ''),
                    'unit_cost' => number_format($row['unit_cost'], 2, '.', ''),
                    'variance_value' => number_format($row['variance_value'], 2, '.', ''),
                    'variance_percentage' => $row['variance_percentage'] === null
                        ? null
                        : number_format($row['variance_percentage'], 2, '.', ''),
                ])
                ->values()
                ->all(),
            'planned_value' => number_format($report['planned_value'], 2, '.', ''),
            'issued_value' => number_format($report['issued_value'], 2, '.', ''),
            'variance_value' => number_format($report['variance_value'], 2, '.', ''),
        ]);
    }

    /**
     * Approve the order (PMO manager)
     *
     * اعتماد مكتب إدارة المشروعات — releases the order from Draft to Pending so
     * manufacturing may start, and records who approved it and when.
     *
     * Refused unless the plan is complete: a positive planned quantity, both
     * planned dates, and a positive quantity on every finished product. That
     * is checked here rather than at completion because by the time an order
     * reaches QA the materials have been issued, and a zero plan silently
     * zeroes the variance, efficiency and loss figures the order is judged on.
     *
     * Retrying after the order has already been approved is a silent success.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Approved" {"data":{"id":31,"type":"work_order","status":{"value":"pending","label":"Pending","color":"warning"},"approvals":{"order_approved":true}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Incomplete plan" {"error":{"code":"business_rule_violated","message":"Manufacturing order WO-202609-0004 is missing: planned quantity، planned start date."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function approveOrder(WorkOrder $workOrder): JsonResponse
    {
        $this->authorizePermission('work_orders.approve_order');

        $this->workOrders->approveOrder($workOrder);

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Start manufacturing
     *
     * Moves a Pending order to In Progress and stamps the actual start time,
     * which is what the manufacturing duration is later measured from.
     *
     * The plan is checked again here, not out of paranoia: it catches orders
     * that reached Pending without passing through `approve-order` — legacy
     * rows and direct database edits.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Started" {"data":{"id":31,"type":"work_order","status":{"value":"in_progress","label":"In Progress","color":"info"},"schedule":{"actual_start_date":"2026-09-12T08:00:00+00:00"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not pending" {"error":{"code":"business_rule_violated","message":"Manufacturing order WO-202609-0004 cannot be started while it is Draft."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function start(WorkOrder $workOrder): JsonResponse
    {
        $this->authorizePermission('work_orders.start');

        $this->workOrders->start($workOrder);

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Declare what was made
     *
     * The only place produced and waste quantities are written. Reported per
     * finished product; the order's totals are the sums, computed here. The
     * order moves to QA Review.
     *
     * A result whose `output_id` does not belong to this order still counts
     * toward the totals but updates no product line — that is how a legacy
     * single-product order reports, by sending one result with no `output_id`.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @bodyParam results object[] required One row per finished product. Send `[]` for a legacy single-product order.
     * @bodyParam results[].output_id integer optional The work-order output line id. Example: 55
     * @bodyParam results[].produced_quantity number required Example: 9
     * @bodyParam results[].waste_quantity number optional Example: 1
     *
     * @response 200 scenario="Submitted" {"data":{"id":31,"type":"work_order","status":{"value":"qa_review","label":"QA Review","color":"warning"},"quantities":{"produced":"9.0000","waste":"1.0000"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not in progress" {"error":{"code":"business_rule_violated","message":"Manufacturing order WO-202609-0004 is not in progress."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function submitQa(SubmitWorkOrderQaRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorizePermission('work_orders.submit_qa');

        $this->workOrders->submitForQa($workOrder, $request->array('results'));

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * QA sign-off
     *
     * اعتماد ضمان الجودة — the mandatory quality gate. An order cannot be
     * completed, and manufacturing cannot be declared finished, without it.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @bodyParam qa_notes string optional What the inspector wants on the record. Example: Passed at second attempt after busbar rework
     *
     * @response 200 scenario="Approved" {"data":{"id":31,"type":"work_order","approvals":{"qa_approved":true,"qa_notes":"Passed"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not in QA review" {"error":{"code":"business_rule_violated","message":"Manufacturing order WO-202609-0004 is not pending QA."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function approveQa(ApproveWorkOrderQaRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorizePermission('work_orders.approve_qa');

        $this->workOrders->approveQa($workOrder, $request->input('qa_notes'));

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Declare manufacturing finished
     *
     * انتهاء التصنيع — the strictest gate on the order, because it tells the
     * whole company the product may be delivered. BOTH the PMO-manager
     * approval and the QA sign-off must already be on the record; neither one
     * alone is enough.
     *
     * It records the finish time and the manufacturing duration, opens a draft
     * **quality sheet** for the QA department (never a second one), and
     * announces to every department that the product is ready.
     *
     * Deliberately touches neither stock nor cost — that is `complete`.
     * Retrying after the first finish is a silent success.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Finished" {"data":{"id":31,"type":"work_order","schedule":{"manufacturing_finished_at":"2026-09-12T16:30:00+00:00","manufacturing_duration_minutes":510},"approvals":{"manufacturing_finished":true}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Missing an approval" {"error":{"code":"business_rule_violated","message":"Manufacturing order WO-202609-0004 still needs: QA status."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function finishManufacturing(WorkOrder $workOrder): JsonResponse
    {
        $this->authorizePermission('work_orders.finish_manufacturing');

        $this->workOrders->finishManufacturing($workOrder);

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Complete the order
     *
     * The accounting moment. In one transaction it produces every finished
     * product into the finished-goods warehouse, consumes the materials this
     * order pulled into work-in-progress, and writes one production entry per
     * product carrying the planned-vs-actual loss (الفاقد).
     *
     * On a multi-product order the single pot of material cost is apportioned
     * across the products by their share of the planned quantity, with the
     * remainder given to the last line — so the per-product rows are
     * meaningful and their totals stay penny-identical to the order's own.
     *
     * Requires QA approval and QA Review status.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Completed" {"data":{"id":31,"type":"work_order","status":{"value":"completed","label":"Completed","color":"success"},"schedule":{"actual_end_date":"2026-09-12T17:00:00+00:00"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="QA gate" {"error":{"code":"business_rule_violated","message":"Manufacturing order WO-202609-0004 cannot be completed without QA approval."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function complete(WorkOrder $workOrder): JsonResponse
    {
        $this->authorizePermission('work_orders.complete');

        $this->workOrders->complete($workOrder);

        return $this->respond(new WorkOrderResource($this->loaded($workOrder->fresh())));
    }

    /**
     * Flatten the request's `specs` object onto the model's own columns.
     *
     * The API groups them because they are one thing to a client — the
     * technical sheet — while the table stores them flat, as the quality sheet
     * and its print view both expect.
     *
     * @return array<string, mixed>
     */
    private function specColumns(Request $request): array
    {
        if (! $request->has('specs')) {
            return [];
        }

        $specs = $request->array('specs');
        $columns = [
            'conductor_type', 'cross_section', 'cross_section_e', 'external_body',
            'protection_degree', 'paint', 'model', 'ampere', 'poles_count',
        ];

        return collect($columns)
            ->filter(fn (string $column) => array_key_exists($column, $specs))
            ->mapWithKeys(fn (string $column) => [$column => $specs[$column]])
            ->all();
    }

    /**
     * Replace the output lines and re-derive the order-level plan from them.
     *
     * `syncDerivedPlan()` is what keeps `planned_quantity` and
     * `output_item_id` honest: they are summaries of the lines, and a summary
     * that can disagree with what it summarizes is worse than not having one.
     *
     * @param  array<int, array<string, mixed>>  $outputs
     */
    private function writeOutputs(WorkOrder $workOrder, array $outputs): void
    {
        $workOrder->outputs()->delete();

        foreach ($outputs as $output) {
            $workOrder->outputs()->create([
                'item_id' => $output['item_id'],
                'planned_quantity' => $output['planned_quantity'],
                'notes' => $output['notes'] ?? null,
            ]);
        }

        $workOrder->load('outputs');
        $workOrder->syncDerivedPlan();
    }

    private function loaded(WorkOrder $workOrder): WorkOrder
    {
        return $workOrder->load(['project', 'bom', 'outputs.item', 'materials.item'])
            ->loadCount(['materials', 'outputs', 'issueVouchers', 'qualitySheets']);
    }
}
