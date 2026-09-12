<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Manufacturing;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Manufacturing\ReplaceVoucherLinesRequest;
use App\Http\Requests\Api\V1\Manufacturing\StoreReturnVoucherRequest;
use App\Http\Resources\Api\V1\Manufacturing\ReturnVoucherResource;
use App\Models\Item;
use App\Models\ReturnVoucher;
use App\Models\WorkOrder;
use App\Services\ReturnVoucherService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group 22. Return vouchers
 *
 * إذن ارتداد — unconsumed material going back from the floor to the store.
 *
 * The exact inverse of an issue voucher: posting transfers each line out of
 * work-in-progress and back into the SAME item's raw stock, and reverses its
 * value off the operation and the work order. The material re-enters under its
 * own code (there is no separate scrap item), so it can simply be issued
 * again.
 *
 * A draft is pre-filled with one line per material the order was issued, each
 * at **quantity zero**, and the warehouse raises only the lines that actually
 * came back. Posting ignores the zeros. That is why the write endpoint allows
 * a zero quantity where an issue voucher would not: deleting a hundred lines
 * to return two is not a workflow anyone completes correctly on a tablet.
 *
 * Scrap items are left out of the pre-fill — scrap does not go back into raw
 * stock.
 */
class ReturnVoucherController extends ApiController
{
    public function __construct(
        private readonly ReturnVoucherService $vouchers,
    ) {}

    /**
     * List return vouchers
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the voucher number. Example: RV-2026
     * @queryParam filter[status] string draft or posted. Example: posted
     * @queryParam filter[work_order] integer Work order id. Example: 31
     * @queryParam filter[voucher_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: voucher_number, voucher_date, total_value, created_at. Example: -voucher_date
     * @queryParam include string Allowed: workOrder. Example: workOrder
     *
     * @response 200 scenario="Success" {"data":[{"id":9,"type":"return_voucher","voucher_number":"RV-202609-0003","status":{"value":"posted","label":"Posted","color":"success"},"total_value":"2000.00","lines_count":4}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReturnVoucher::class);

        $vouchers = ApiQuery::for(ReturnVoucher::query()->withCount('lines'), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'work_order' => ApiQuery::exact('work_order_id'),
                'voucher_date' => ApiQuery::dateBetween('voucher_date'),
            ])
            ->allowSearch(['voucher_number'])
            ->allowSorts(['voucher_number', 'voucher_date', 'total_value', 'created_at'])
            ->allowIncludes(['workOrder'])
            ->defaultSort('-voucher_date')
            ->paginate();

        return $this->respondPaginated(ReturnVoucherResource::collection($vouchers));
    }

    /**
     * Show a return voucher
     *
     * @authenticated
     *
     * @urlParam return_voucher integer required The voucher id. Example: 9
     *
     * @response 200 scenario="Success" {"data":{"id":9,"type":"return_voucher","voucher_number":"RV-202609-0003","status":{"value":"draft","label":"Draft","color":"gray"},"lines":[{"item_id":7,"quantity":"0.0000","unit_cost":"1000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(ReturnVoucher $returnVoucher): JsonResponse
    {
        $this->authorize('view', $returnVoucher);

        return $this->respond(new ReturnVoucherResource($this->loaded($returnVoucher)));
    }

    /**
     * Open a draft return voucher
     *
     * Pre-filled with every material the order was actually issued, at quantity
     * zero. Raise the lines that came back and post; the rest are ignored.
     *
     * @authenticated
     *
     * @bodyParam work_order_id integer required The order the material was issued to. Example: 31
     *
     * @response 201 scenario="Created" {"data":{"id":9,"type":"return_voucher","voucher_number":"RV-202609-0003","status":{"value":"draft","label":"Draft","color":"gray"},"lines":[{"item_id":7,"quantity":"0.0000","unit_cost":"1000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreReturnVoucherRequest $request): JsonResponse
    {
        $this->authorize('create', ReturnVoucher::class);

        $workOrder = WorkOrder::query()->findOrFail($request->integer('work_order_id'));

        $voucher = $this->vouchers->createFromWorkOrder($workOrder);

        return $this->respondCreated(new ReturnVoucherResource($this->loaded($voucher)));
    }

    /**
     * Replace the lines of a draft voucher
     *
     * The whole list in one atomic request. Zero quantities are legal and are
     * simply skipped at posting.
     *
     * @authenticated
     *
     * @urlParam return_voucher integer required The voucher id. Example: 9
     *
     * @bodyParam lines object[] required The complete list. Send `[]` to clear it.
     * @bodyParam lines[].item_id integer required Example: 7
     * @bodyParam lines[].quantity number required Zero means "nothing of this came back". Example: 2
     * @bodyParam lines[].unit_cost number optional Defaults to the item's current cost. Example: 1000
     *
     * @response 200 scenario="Replaced" {"data":{"id":9,"type":"return_voucher","lines":[{"item_id":7,"quantity":"2.0000","unit_cost":"1000.00","line_value":"2000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceLines(ReplaceVoucherLinesRequest $request, ReturnVoucher $returnVoucher): JsonResponse
    {
        $this->authorize('update', $returnVoucher);

        $this->writeLines($returnVoucher, $request->array('lines'));

        return $this->respond(new ReturnVoucherResource($this->loaded($returnVoucher->fresh())));
    }

    /**
     * Post a return voucher
     *
     * Every line with a positive quantity is transferred out of
     * work-in-progress back into raw stock, and its value is reversed off the
     * operation and the work order. Lines left at zero are ignored.
     *
     * Refused when nothing on the voucher carries a quantity — posting an
     * all-zero voucher would sign a document that did nothing — and refused
     * when work-in-progress does not hold what the voucher claims came back.
     *
     * @authenticated
     *
     * @urlParam return_voucher integer required The voucher id. Example: 9
     *
     * @response 200 scenario="Posted" {"data":{"id":9,"type":"return_voucher","status":{"value":"posted","label":"Posted","color":"success"},"total_value":"2000.00","signed_at":"2026-09-12T11:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Nothing to return" {"error":{"code":"business_rule_violated","message":"Voucher RV-202609-0003 has no lines to post."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function post(ReturnVoucher $returnVoucher): JsonResponse
    {
        $this->authorize('post', $returnVoucher);

        $this->vouchers->post($returnVoucher);

        return $this->respond(new ReturnVoucherResource($this->loaded($returnVoucher->fresh())));
    }

    /**
     * Delete a draft return voucher
     *
     * Draft only, for the same reason as every other posted document: the stock
     * has already moved.
     *
     * @authenticated
     *
     * @urlParam return_voucher integer required The voucher id. Example: 9
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(ReturnVoucher $returnVoucher): JsonResponse
    {
        $this->authorize('delete', $returnVoucher);

        if ($returnVoucher->isPosted()) {
            throw new DomainException(__('errors.api.voucher_not_draft'));
        }

        $returnVoucher->delete();

        return $this->respondNoContent();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeLines(ReturnVoucher $voucher, array $lines): void
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

    private function loaded(ReturnVoucher $voucher): ReturnVoucher
    {
        return $voucher->load(['lines.item', 'workOrder'])->loadCount('lines');
    }
}
