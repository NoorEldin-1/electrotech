<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Manufacturing;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Manufacturing\PostIssueVoucherRequest;
use App\Http\Requests\Api\V1\Manufacturing\ReplaceVoucherLinesRequest;
use App\Http\Requests\Api\V1\Manufacturing\StoreIssueVoucherRequest;
use App\Http\Resources\Api\V1\Manufacturing\IssueVoucherResource;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\WorkOrder;
use App\Services\IssueVoucherService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group 21. Issue vouchers
 *
 * إذن صرف — material leaving the raw store for a work order.
 *
 * A voucher is always raised **against a work order**, and the server
 * pre-fills it with what that order still needs: its material plan, minus what
 * other vouchers already carry, net of posted returns. The warehouse then
 * edits the list and posts it. Posting transfers every line from raw materials
 * into work-in-progress and loads the value onto the operation.
 *
 * The rule that shapes this whole module is the **excess gate**. A voucher
 * that would take the order past its plan is refused with
 * `422 issue_excess_requires_approval`, carrying the offending rows in
 * `details.excess`. Going over is allowed — a broken part has to be replaced —
 * but only as a decision: a user holding `issue_vouchers.approve_excess`
 * retries with `allow_excess` and a written reason, and both are stamped on
 * the document.
 *
 * Only POSTED vouchers count as already-issued at that gate. A sibling draft
 * has not moved anything, so it cannot make a voucher "excess". Drafts *are*
 * counted when suggesting lines, so two store keepers do not each prepare a
 * voucher for the same remaining material.
 */
class IssueVoucherController extends ApiController
{
    public function __construct(
        private readonly IssueVoucherService $vouchers,
    ) {}

    /**
     * List issue vouchers
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the voucher number. Example: IV-2026
     * @queryParam filter[status] string draft or posted. Example: posted
     * @queryParam filter[work_order] integer Work order id. Example: 31
     * @queryParam filter[has_excess] boolean Only vouchers that went over the plan. Example: true
     * @queryParam filter[voucher_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: voucher_number, voucher_date, total_value, created_at. Example: -voucher_date
     * @queryParam include string Allowed: workOrder. Example: workOrder
     *
     * @response 200 scenario="Success" {"data":[{"id":48,"type":"issue_voucher","voucher_number":"IV-202609-0012","status":{"value":"posted","label":"Posted","color":"success"},"total_value":"40000.00","excess":{"has_excess":false},"lines_count":3}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IssueVoucher::class);

        $vouchers = ApiQuery::for(IssueVoucher::query()->withCount('lines'), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'work_order' => ApiQuery::exact('work_order_id'),
                'has_excess' => ApiQuery::boolean('has_excess'),
                'voucher_date' => ApiQuery::dateBetween('voucher_date'),
            ])
            ->allowSearch(['voucher_number'])
            ->allowSorts(['voucher_number', 'voucher_date', 'total_value', 'created_at'])
            ->allowIncludes(['workOrder'])
            ->defaultSort('-voucher_date')
            ->paginate();

        return $this->respondPaginated(IssueVoucherResource::collection($vouchers));
    }

    /**
     * Show an issue voucher
     *
     * @authenticated
     *
     * @urlParam issue_voucher integer required The voucher id. Example: 48
     *
     * @response 200 scenario="Success" {"data":{"id":48,"type":"issue_voucher","voucher_number":"IV-202609-0012","status":{"value":"draft","label":"Draft","color":"gray"},"lines":[{"item_id":7,"quantity":"40.0000","unit_cost":"1000.00","line_value":"40000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(IssueVoucher $issueVoucher): JsonResponse
    {
        $this->authorize('view', $issueVoucher);

        return $this->respond(new IssueVoucherResource($this->loaded($issueVoucher)));
    }

    /**
     * Open a draft issue voucher
     *
     * Pre-filled from the work order with what it **still** needs, not its full
     * plan: an order issued in two batches must not have the second voucher
     * propose the whole recipe again. No stock moves — the warehouse reviews,
     * adjusts the lines, then posts.
     *
     * Refused when the order has no material plan at all, and refused when
     * everything it needs has already been issued. Those are different
     * messages on purpose: the first is a planning problem, the second means
     * there is simply nothing to do.
     *
     * @authenticated
     *
     * @bodyParam work_order_id integer required The order to issue against. Example: 31
     *
     * @response 201 scenario="Created" {"data":{"id":48,"type":"issue_voucher","voucher_number":"IV-202609-0012","status":{"value":"draft","label":"Draft","color":"gray"},"lines":[{"item_id":7,"quantity":"15.0000","unit_cost":"1000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Nothing left to issue" {"error":{"code":"business_rule_violated","message":"Nothing is left to issue for WO-202609-0004."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreIssueVoucherRequest $request): JsonResponse
    {
        $this->authorize('create', IssueVoucher::class);

        $workOrder = WorkOrder::query()->findOrFail($request->integer('work_order_id'));

        $voucher = $this->vouchers->createFromWorkOrder($workOrder);

        return $this->respondCreated(new IssueVoucherResource($this->loaded($voucher)));
    }

    /**
     * Replace the lines of a draft voucher
     *
     * The whole list in one atomic request — see the work order's material
     * endpoint for why replace beats merge over a weak link.
     *
     * `unit_cost` falls back to the item card when omitted. A posted voucher
     * is immutable: its stock has already moved, so the correction is a return
     * voucher, not an edit.
     *
     * @authenticated
     *
     * @urlParam issue_voucher integer required The voucher id. Example: 48
     *
     * @bodyParam lines object[] required The complete list. Send `[]` to clear it.
     * @bodyParam lines[].item_id integer required Example: 7
     * @bodyParam lines[].quantity number required Example: 15
     * @bodyParam lines[].unit_cost number optional Defaults to the item's current cost. Example: 1000
     *
     * @response 200 scenario="Replaced" {"data":{"id":48,"type":"issue_voucher","lines":[{"item_id":7,"quantity":"15.0000","unit_cost":"1000.00","line_value":"15000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 403 scenario="Already posted" {"error":{"code":"forbidden","message":"You do not have permission to perform this action."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceLines(ReplaceVoucherLinesRequest $request, IssueVoucher $issueVoucher): JsonResponse
    {
        $this->authorize('update', $issueVoucher);

        $this->writeLines($issueVoucher, $request->array('lines'));

        return $this->respond(new IssueVoucherResource($this->loaded($issueVoucher->fresh())));
    }

    /**
     * Post an issue voucher
     *
     * The moment the material leaves the store. Every line is transferred from
     * raw materials into work-in-progress, the total is loaded onto the
     * operation (the project is the cost centre) and accrued onto the work
     * order, and the voucher is signed — all in one transaction.
     *
     * **The excess flow.** Before any stock moves, the voucher is compared
     * against what the order still needs. If it goes over, the response is
     * `422 issue_excess_requires_approval` with the offending rows in
     * `details.excess` — item by item: what was required, what was already
     * issued, what is left, what this voucher asks for, and the overage. Show
     * those rows. If the user holds `issue_vouchers.approve_excess`, offer to
     * retry with `allow_excess: true` and a reason; otherwise the only way
     * forward is to edit the lines down.
     *
     * Posting twice is refused, not ignored — which is what makes
     * double-posting impossible even if the `Idempotency-Key` is fumbled.
     *
     * @authenticated
     *
     * @urlParam issue_voucher integer required The voucher id. Example: 48
     *
     * @bodyParam allow_excess boolean optional Approve issuing more than the order still needs. Requires `issue_vouchers.approve_excess`. Example: true
     * @bodyParam excess_reason string required_if_accepted Why the excess is justified. Stamped on the voucher. Example: Two busbars damaged during assembly
     *
     * @response 200 scenario="Posted" {"data":{"id":48,"type":"issue_voucher","status":{"value":"posted","label":"Posted","color":"success"},"total_value":"15000.00","signed_at":"2026-09-12T09:15:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Excess needs approval" {"error":{"code":"issue_excess_requires_approval","message":"Voucher IV-202609-0012 issues more than the order still needs.","details":{"excess":[{"item_id":7,"item_name":"Copper busbar 120mm","required":40,"previously_issued":25,"remaining":15,"this_voucher":18,"excess":3}]}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Insufficient stock" {"error":{"code":"business_rule_violated","message":"Insufficient stock for 'Copper busbar 120mm' in Raw Materials. Available: 12, Requested: 40."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function post(PostIssueVoucherRequest $request, IssueVoucher $issueVoucher): JsonResponse
    {
        $this->authorize('post', $issueVoucher);

        $allowExcess = $request->boolean('allow_excess');

        // Approving an overage is a second, narrower permission. Checked here
        // rather than inside the service because the service is shared with
        // the panel, where the same gate is applied by hiding the action.
        if ($allowExcess) {
            $this->authorize('approveExcess', $issueVoucher);
        }

        $this->vouchers->post($issueVoucher, $allowExcess, $request->input('excess_reason'));

        return $this->respond(new IssueVoucherResource($this->loaded($issueVoucher->fresh())));
    }

    /**
     * Preview the excess on a draft voucher
     *
     * The same comparison `post` runs, without posting: the rows this voucher
     * goes over on, if any. An empty list means the voucher will post without
     * needing an approval.
     *
     * Worth calling before showing the post button, so the user learns what
     * they are about to be asked before they are asked it.
     *
     * @authenticated
     *
     * @urlParam issue_voucher integer required The voucher id. Example: 48
     *
     * @response 200 scenario="Over the plan" {"data":[{"item_id":7,"item_name":"Copper busbar 120mm","required":"40.0000","previously_issued":"25.0000","remaining":"15.0000","this_voucher":"18.0000","excess":"3.0000"}],"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function excessPreview(IssueVoucher $issueVoucher): JsonResponse
    {
        $this->authorize('view', $issueVoucher);

        $rows = array_map(fn (array $row) => [
            'item_id' => $row['item_id'],
            'item_name' => $row['item_name'],
            'required' => number_format($row['required'], 4, '.', ''),
            'previously_issued' => number_format($row['previously_issued'], 4, '.', ''),
            'remaining' => number_format($row['remaining'], 4, '.', ''),
            'this_voucher' => number_format($row['this_voucher'], 4, '.', ''),
            'excess' => number_format($row['excess'], 4, '.', ''),
        ], $this->vouchers->excessReport($issueVoucher));

        return $this->respondCollection($rows);
    }

    /**
     * Delete a draft issue voucher
     *
     * Draft only. A posted voucher has already moved stock and loaded cost onto
     * the operation; the correction for a mistake is a return voucher, not a
     * deletion that leaves the ledger holding movements with no source.
     *
     * @authenticated
     *
     * @urlParam issue_voucher integer required The voucher id. Example: 48
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(IssueVoucher $issueVoucher): JsonResponse
    {
        $this->authorize('delete', $issueVoucher);

        if ($issueVoucher->isPosted()) {
            throw new DomainException(__('errors.api.voucher_not_draft'));
        }

        $issueVoucher->delete();

        return $this->respondNoContent();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeLines(IssueVoucher $voucher, array $lines): void
    {
        $itemCosts = Item::query()
            ->whereIn('id', array_column($lines, 'item_id'))
            ->pluck('unit_cost', 'id');

        DB::transaction(function () use ($voucher, $lines, $itemCosts): void {
            $voucher->lines()->delete();

            foreach ($lines as $line) {
                $voucher->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'] ?? $itemCosts[$line['item_id']] ?? 0,
                ]);
            }
        });
    }

    private function loaded(IssueVoucher $voucher): IssueVoucher
    {
        return $voucher->load(['lines.item', 'workOrder'])->loadCount('lines');
    }
}
