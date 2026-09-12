<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Enums\WarehouseType;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Inventory\InventoryLevelResource;
use App\Http\Resources\Api\V1\Inventory\InventoryTransactionResource;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Services\StockCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 17. Inventory
 *
 * What is in the warehouses, how it got there, and what it is worth.
 *
 * Everything here is **read-only**. Stock is never moved by writing to a
 * balance: it moves because a document was posted — an addition voucher, an
 * issue voucher, a reservation. Exposing a writable balance would let a client
 * put the ledger and the on-hand figure out of step with nothing to reconcile
 * them against, which is the one failure an inventory system cannot recover
 * from.
 */
class InventoryController extends ApiController
{
    public function __construct(private readonly StockCardService $stockCard) {}

    /**
     * List stock balances
     *
     * One row per item per warehouse. `available` (= on hand − on hold) is the
     * number a planner may promise; `on_hand` is only what is physically
     * present, some of which may already be reserved.
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[item] integer Item id. Example: 7
     * @queryParam filter[warehouse] string A warehouse_type value. Example: raw_materials
     * @queryParam filter[in_stock] boolean Only rows with something on hand. Example: true
     * @queryParam filter[below_minimum] boolean Only rows whose available quantity is under the item's minimum_stock. Example: true
     * @queryParam sort string Allowed: on_hand_quantity, on_hold_quantity, updated_at. Example: -on_hand_quantity
     *
     * @response 200 scenario="Success" {"data":[{"id":3,"type":"inventory_level","item_id":7,"warehouse":{"value":"raw_materials","label":"Raw Materials","color":null},"on_hand":"120.0000","on_hold":"20.0000","available":"100.0000","value":"49500.00"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function levels(Request $request): JsonResponse
    {
        // Reading balances is reading the stock ledger, which is what
        // `transactions.view` gates in the panel.
        $this->authorize('viewAny', InventoryTransaction::class);

        $levels = ApiQuery::for(Inventory::query()->with('item'), $request)
            ->allowFilters([
                'item' => ApiQuery::exact('item_id'),
                'warehouse' => ApiQuery::exact('warehouse_type'),
                'in_stock' => fn ($query, $value) => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? $query->where('on_hand_quantity', '>', 0)
                    : $query->where('on_hand_quantity', '<=', 0),

                // Compared per warehouse row against the item's threshold. A
                // join rather than a per-row accessor, so the page costs one
                // query however many rows it holds.
                'below_minimum' => fn ($query, $value) => $query->whereHas(
                    'item',
                    fn ($item) => $item->whereColumn(
                        'items.minimum_stock',
                        '>',
                        \Illuminate\Support\Facades\DB::raw('inventories.on_hand_quantity - inventories.on_hold_quantity'),
                    ),
                ),
            ])
            ->allowSorts(['on_hand_quantity', 'on_hold_quantity', 'updated_at'])
            ->defaultSort('-updated_at')
            ->paginate();

        return $this->respondPaginated(InventoryLevelResource::collection($levels));
    }

    /**
     * The stock ledger
     *
     * Every movement, newest first. Remember that `hold` and `release` change
     * availability only — sum `in` and `out` alone when valuing stock.
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[item] integer Item id. Example: 7
     * @queryParam filter[warehouse] string A warehouse_type value. Example: raw_materials
     * @queryParam filter[movement] string One or more transaction_type values, comma-separated. Example: in,out
     * @queryParam filter[created] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: created_at, quantity. Example: -created_at
     * @queryParam include string Allowed: item, performedBy. Example: item
     *
     * @response 200 scenario="Success" {"data":[{"id":91,"type":"inventory_transaction","item_id":7,"movement":{"value":"in","label":"In","color":"success"},"quantity":"40.0000","unit_cost":"1000.00","reference":{"type":"AdditionVoucher","id":15}}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function transactions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryTransaction::class);

        $transactions = ApiQuery::for(InventoryTransaction::query(), $request)
            ->allowFilters([
                'item' => ApiQuery::exact('item_id'),
                'warehouse' => ApiQuery::exact('warehouse_type'),
                'movement' => ApiQuery::exact('type'),
                'created' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSorts(['created_at', 'quantity'])
            ->allowIncludes(['item', 'performedBy'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->respondPaginated(InventoryTransactionResource::collection($transactions));
    }

    /**
     * Item stock card
     *
     * The valued movement history for one item in one warehouse (كارت الصنف):
     * every receipt and issue with its running quantity, weighted-average unit
     * cost and balance value.
     *
     * `warehouse` defaults to the item's home warehouse. Reservations are
     * excluded — they change availability, not stock value — and scrap
     * variants are kept separate, because averaging two distinct items into one
     * pool would be accounting-incorrect.
     *
     * On the **reports** rate limiter: it walks the item's whole movement
     * history, so it is materially more expensive than a page of the ledger.
     *
     * @authenticated
     *
     * @urlParam item integer required The item id. Example: 7
     * @queryParam warehouse string A warehouse_type value; defaults to the item's home warehouse. Example: raw_materials
     *
     * @response 200 scenario="Success" {"data":{"warehouse":{"value":"raw_materials","label":"Raw Materials","color":null},"item":{"id":7,"name":"Copper Busbar","sku":"CU-BB-1"},"rows":[{"id":91,"date":"2026-09-01T09:00:00+00:00","reference":"Addition voucher AV-202609-0031","in_qty":"40.0000","in_price":"1000.00","in_value":"40000.00","out_qty":null,"balance_qty":"40.0000","balance_value":"40000.00"}],"totals":{"quantity":"40.0000","unit_cost":"1000.00","value":"40000.00"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function stockCard(Request $request, Item $item): JsonResponse
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $this->authorize('view', $item);

        $warehouse = $request->filled('warehouse')
            ? WarehouseType::tryFrom($request->string('warehouse')->toString())
            : null;

        if ($request->filled('warehouse') && $warehouse === null) {
            $this->failValidation('warehouse', 'warehouse must be one of the warehouse_type enum values.');
        }

        $card = $this->stockCard->build($item, $warehouse);

        return $this->respond([
            'warehouse' => \App\Http\Api\EnumPresenter::present($card['warehouse']),
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'unit' => \App\Http\Api\EnumPresenter::present($item->unit),
            ],

            // The service returns floats and Carbon instances for the panel's
            // Blade view. Re-shaping them here keeps the API's promise that
            // dates are ISO-8601 and every decimal is a string, rather than
            // letting one endpoint quietly break the contract.
            'rows' => array_map(
                fn (array $row): array => [
                    'id' => $row['id'],
                    'date' => $row['date']?->toIso8601String(),
                    'reference' => $row['reference'],
                    'performed_by' => $row['performed_by'],
                    'in_qty' => $this->decimalOrNull($row['in_qty'], 4),
                    'in_price' => $this->decimalOrNull($row['in_price'], 2),
                    'in_value' => $this->decimalOrNull($row['in_value'], 2),
                    'out_qty' => $this->decimalOrNull($row['out_qty'], 4),
                    'out_price' => $this->decimalOrNull($row['out_price'], 2),
                    'out_value' => $this->decimalOrNull($row['out_value'], 2),
                    'balance_qty' => $this->decimalOrNull($row['balance_qty'] ?? null, 4),
                    'balance_price' => $this->decimalOrNull($row['balance_price'] ?? null, 2),
                    'balance_value' => $this->decimalOrNull($row['balance_value'] ?? null, 2),
                ],
                $card['rows'],
            ),

            'totals' => [
                'quantity' => $this->decimalOrNull($card['totals']['quantity'], 4),
                'unit_cost' => $this->decimalOrNull($card['totals']['unit_cost'], 2),
                'value' => $this->decimalOrNull($card['totals']['value'], 2),
            ],
        ]);
    }

    private function decimalOrNull(mixed $value, int $precision): ?string
    {
        return $value === null ? null : number_format((float) $value, $precision, '.', '');
    }
}
