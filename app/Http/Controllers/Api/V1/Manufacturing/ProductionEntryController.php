<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Manufacturing;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Manufacturing\ProductionEntryResource;
use App\Models\ProductionEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 24. Production entries
 *
 * الإنتاج والفاقد — what a completed work order actually produced, and what it
 * lost doing so. One row per finished product per completed order.
 *
 * **Read-only, deliberately.** `ProductionEntryPolicy` answers false to
 * create, update and delete, and this controller has no write endpoints to
 * match. These rows are written by the work order's `complete` transition, at
 * the same moment the stock moves. A writable production entry would let the
 * loss figures be edited away from the movements that produced them, with
 * nothing left to reconcile against.
 *
 * `costs.loss_value` is the money the scrap cost — scrap × the actual cost per
 * produced unit — not the quantity. It is the figure the value-based loss
 * report aggregates, which is why it is published here rather than left to
 * each client to re-derive.
 */
class ProductionEntryController extends ApiController
{
    /**
     * List production entries
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the operation name. Example: Main distribution
     * @queryParam filter[work_order] integer Work order id. Example: 31
     * @queryParam filter[output_item] integer Finished-product item id. Example: 21
     * @queryParam filter[entry_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: entry_date, produced_quantity, scrap_quantity, created_at. Example: -entry_date
     * @queryParam include string Allowed: workOrder, outputItem. Example: outputItem
     *
     * @response 200 scenario="Success" {"data":[{"id":77,"type":"production_entry","work_order_id":31,"output_item_id":21,"quantities":{"planned":"10.0000","produced":"9.0000","scrap":"1.0000","scrap_percentage":"10.00"},"costs":{"actual_material":"43000.00","loss_value":"4777.78"}}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductionEntry::class);

        $entries = ApiQuery::for(ProductionEntry::query(), $request)
            ->allowFilters([
                'work_order' => ApiQuery::exact('work_order_id'),
                'output_item' => ApiQuery::exact('output_item_id'),
                'entry_date' => ApiQuery::dateBetween('entry_date'),
            ])
            ->allowSearch(['operation_name'])
            ->allowSorts(['entry_date', 'produced_quantity', 'scrap_quantity', 'created_at'])
            ->allowIncludes(['workOrder', 'outputItem'])
            ->defaultSort('-entry_date')
            ->paginate();

        return $this->respondPaginated(ProductionEntryResource::collection($entries));
    }

    /**
     * Show a production entry
     *
     * @authenticated
     *
     * @urlParam production_entry integer required The entry id. Example: 77
     *
     * @response 200 scenario="Success" {"data":{"id":77,"type":"production_entry","work_order_id":31,"quantities":{"produced":"9.0000","scrap":"1.0000"},"costs":{"loss_value":"4777.78","loss_value_percentage":"11.11"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(ProductionEntry $productionEntry): JsonResponse
    {
        $this->authorize('view', $productionEntry);

        return $this->respond(new ProductionEntryResource(
            $productionEntry->load(['workOrder', 'outputItem']),
        ));
    }
}
