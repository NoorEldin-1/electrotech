<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Inventory\StoreDepreciationVoucherRequest;
use App\Http\Requests\Api\V1\Inventory\UpdateDepreciationVoucherRequest;
use App\Http\Resources\Api\V1\Inventory\DepreciationVoucherResource;
use App\Models\DepreciationVoucher;
use App\Models\WorkOrder;
use App\Services\DepreciationVoucherService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group 19. Depreciation vouchers
 *
 * Writing off manufacturing loss (إذن إهلاك): material that went into
 * work-in-progress and did not come out as product.
 *
 * The flow is deliberately two-step. Creating one against a work order builds
 * a **draft pre-filled with everything that was issued to that order, all at
 * quantity zero** — the user then sets quantities only for what was actually
 * lost and leaves the rest alone. Lines still at zero are ignored when it
 * posts.
 *
 * Posting takes the loss out of the WIP balance (lowering the item's quantity
 * and value on its stock card) and writes a balanced journal entry carrying
 * the value to a loss account. `loss_type` decides the operation-cost
 * treatment — see DepreciationVoucherResource.
 *
 * A work order is required, which is why this document sits at the boundary
 * with the manufacturing module. Only the reference is needed here; the work
 * order's own endpoints arrive in Module 7.
 */
class DepreciationVoucherController extends ApiController
{
    public function __construct(private readonly DepreciationVoucherService $vouchers) {}

    /**
     * List depreciation vouchers
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the voucher number. Example: DV-2026
     * @queryParam filter[status] string draft or posted. Example: posted
     * @queryParam filter[loss_type] string natural or abnormal. Example: abnormal
     * @queryParam filter[work_order] integer Work order id. Example: 5
     * @queryParam filter[voucher_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: voucher_number, voucher_date, total_value, created_at. Example: -voucher_date
     * @queryParam include string Allowed: workOrder. Example: workOrder
     *
     * @response 200 scenario="Success" {"data":[{"id":6,"type":"depreciation_voucher","voucher_number":"DV-202609-0003","status":{"value":"posted","label":"Posted","color":"success"},"loss_type":{"value":"abnormal","label":"Abnormal","color":"danger"},"total_value":"3200.00","lines_count":2}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DepreciationVoucher::class);

        $vouchers = ApiQuery::for(DepreciationVoucher::query()->withCount('lines'), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'loss_type' => ApiQuery::exact('loss_type'),
                'work_order' => ApiQuery::exact('work_order_id'),
                'voucher_date' => ApiQuery::dateBetween('voucher_date'),
            ])
            ->allowSearch(['voucher_number'])
            ->allowSorts(['voucher_number', 'voucher_date', 'total_value', 'created_at'])
            ->allowIncludes(['workOrder'])
            ->defaultSort('-voucher_date')
            ->paginate();

        return $this->respondPaginated(DepreciationVoucherResource::collection($vouchers));
    }

    /**
     * Show a depreciation voucher
     *
     * @authenticated
     *
     * @urlParam depreciation_voucher integer required The voucher id. Example: 6
     *
     * @response 200 scenario="Success" {"data":{"id":6,"type":"depreciation_voucher","voucher_number":"DV-202609-0003","loss_type":{"value":"abnormal","label":"Abnormal","color":"danger"},"lines":[{"item_id":7,"quantity":"3.0000","unit_cost":"1000.00","line_value":"3000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(DepreciationVoucher $depreciationVoucher): JsonResponse
    {
        $this->authorize('view', $depreciationVoucher);

        return $this->respond(new DepreciationVoucherResource(
            $depreciationVoucher->load(['lines.item', 'workOrder']),
        ));
    }

    /**
     * Start a depreciation voucher for a work order
     *
     * Returns a draft pre-filled with every material that was issued to the
     * order, each at **quantity zero**. Set the quantities that were actually
     * lost with `PATCH`, then post.
     *
     * The pre-fill is what makes this usable on a phone: the alternative is
     * asking someone on the shop floor to recall which of forty materials went
     * into the job.
     *
     * @authenticated
     *
     * @bodyParam work_order_id integer required The work order the loss belongs to. Example: 5
     *
     * @response 201 scenario="Created" {"data":{"id":7,"type":"depreciation_voucher","voucher_number":"DV-202609-0004","status":{"value":"draft","label":"Draft","color":"gray"},"loss_type":{"value":"abnormal","label":"Abnormal","color":"danger"},"lines":[{"item_id":7,"quantity":"0.0000","unit_cost":"1000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreDepreciationVoucherRequest $request): JsonResponse
    {
        $this->authorize('create', DepreciationVoucher::class);

        $workOrder = WorkOrder::findOrFail($request->integer('work_order_id'));

        $voucher = $this->vouchers->createFromWorkOrder($workOrder);

        return $this->respondCreated(new DepreciationVoucherResource(
            $voucher->load(['lines.item', 'workOrder']),
        ));
    }

    /**
     * Update a draft voucher
     *
     * Sets the loss quantities and the loss type. Sending `lines` replaces the
     * whole list; lines left at zero quantity are ignored when it posts, so the
     * usual flow is to send the pre-filled list back with the lost quantities
     * filled in.
     *
     * Draft only — a posted voucher has already moved stock and written a
     * journal entry.
     *
     * @authenticated
     *
     * @urlParam depreciation_voucher integer required The voucher id. Example: 7
     * @bodyParam loss_type string optional natural or abnormal. Abnormal loss is reversed off the operation's cost; natural loss stays on it. Example: abnormal
     * @bodyParam voucher_date date optional Example: 2026-09-08
     * @bodyParam notes string optional Example: Damaged during cutting
     * @bodyParam lines object[] optional Replaces the line list.
     * @bodyParam lines[].item_id integer required Example: 7
     * @bodyParam lines[].quantity number required The quantity lost; 0 means this material was not lost. Example: 3
     * @bodyParam lines[].unit_cost number required Example: 1000
     *
     * @response 200 scenario="Success" {"data":{"id":7,"type":"depreciation_voucher","loss_type":{"value":"abnormal","label":"Abnormal","color":"danger"},"lines":[{"item_id":7,"quantity":"3.0000","unit_cost":"1000.00","line_value":"3000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Already posted" {"error":{"code":"business_rule_violated","message":"Only a draft voucher can be edited or deleted."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateDepreciationVoucherRequest $request, DepreciationVoucher $depreciationVoucher): JsonResponse
    {
        $this->authorize('update', $depreciationVoucher);
        $this->assertDraft($depreciationVoucher);

        DB::transaction(function () use ($request, $depreciationVoucher): void {
            $depreciationVoucher->update($request->safe()->only(['loss_type', 'voucher_date', 'notes']));

            if ($request->has('lines')) {
                $depreciationVoucher->lines()->delete();

                foreach ($request->array('lines') as $line) {
                    $depreciationVoucher->lines()->create([
                        'item_id' => $line['item_id'],
                        'quantity' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                    ]);
                }
            }
        });

        return $this->respond(new DepreciationVoucherResource(
            $depreciationVoucher->fresh()->load(['lines.item', 'workOrder']),
        ));
    }

    /**
     * Post a depreciation voucher
     *
     * Deducts each positive line from the item's **work-in-progress** balance,
     * carries the value to a loss account with a balanced journal entry, and —
     * for abnormal loss only — reverses the value off the operation.
     *
     * Refused if every line is still at zero: there would be nothing to write
     * off, and a "posted" voucher recording no loss is worse than no voucher.
     * Also refused if the WIP balance is short, which usually means the
     * materials were never issued to the order in the first place.
     *
     * Gated by `depreciation_vouchers.post`, separately from `create`.
     *
     * @authenticated
     *
     * @urlParam depreciation_voucher integer required The voucher id. Example: 7
     *
     * @response 200 scenario="Posted" {"data":{"id":7,"type":"depreciation_voucher","status":{"value":"posted","label":"Posted","color":"success"},"total_value":"3000.00","journal_entry_id":42},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Nothing to write off" {"error":{"code":"business_rule_violated","message":"Voucher DV-202609-0004 has no items to post."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function post(DepreciationVoucher $depreciationVoucher): JsonResponse
    {
        $this->authorize('post', $depreciationVoucher);

        $this->vouchers->post($depreciationVoucher);

        return $this->respond(new DepreciationVoucherResource(
            $depreciationVoucher->fresh()->load(['lines.item', 'workOrder']),
        ));
    }

    /**
     * Delete a draft voucher
     *
     * @authenticated
     *
     * @urlParam depreciation_voucher integer required The voucher id. Example: 7
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(DepreciationVoucher $depreciationVoucher): JsonResponse
    {
        $this->authorize('delete', $depreciationVoucher);
        $this->assertDraft($depreciationVoucher);

        $depreciationVoucher->delete();

        return $this->respondNoContent();
    }

    private function assertDraft(DepreciationVoucher $voucher): void
    {
        if ($voucher->isPosted()) {
            throw new DomainException(__('errors.api.voucher_not_draft'));
        }
    }
}
