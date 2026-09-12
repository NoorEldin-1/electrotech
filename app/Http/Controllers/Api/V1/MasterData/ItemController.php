<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\StoreItemRequest;
use App\Http\Requests\Api\V1\MasterData\UpdateItemRequest;
use App\Http\Resources\Api\V1\MasterData\ItemResource;
use App\Models\Item;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 7. Items
 *
 * The material catalogue (الأصناف): raw materials, semi-finished parts,
 * finished goods and consumables. Every BOM line, purchase-order line, voucher
 * line and stock balance in the platform points at one of these, so this is
 * usually the first list a client caches after logging in.
 */
class ItemController extends ApiController
{
    /**
     * List items
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches name, SKU or description. Example: copper
     * @queryParam filter[type] string Item type; comma-separate for OR. Example: raw_material,consumable
     * @queryParam filter[unit] string Unit of measure. Example: kg
     * @queryParam filter[is_scrap] boolean Only scrap variants (true) or only real items (false). Example: false
     * @queryParam filter[below_minimum] boolean Only items whose total available quantity is under minimum_stock. Example: true
     * @queryParam filter[created] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Comma-separated; prefix with - for descending. Allowed: name, sku, unit_cost, created_at. Example: name
     * @queryParam include string Comma-separated relations. Allowed: inventories, scrapSource. Example: inventories
     * @queryParam updated_after string ISO-8601 timestamp; returns only rows changed since then. Example: 2026-08-01T10:00:00Z
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"type":"item","name":"Copper Busbar 100x10","sku":"CU-BB-10010","item_type":{"value":"raw_material","label":"Raw Material","color":"info"},"unit":{"value":"kg","label":"Kilogram","color":null},"unit_cost":"412.50","minimum_stock":"50.0000","is_scrap":false,"home_warehouse":{"value":"raw_materials","label":"Raw Materials","color":null}}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Item::class);

        $items = ApiQuery::for(Item::query(), $request)
            ->allowFilters([
                'type' => ApiQuery::exact('type'),
                'unit' => ApiQuery::exact('unit'),
                'is_scrap' => ApiQuery::boolean('is_scrap'),

                // "Below minimum" is answered in SQL against the summed
                // balances rather than by hydrating every item and calling
                // isBelowMinimumStock(): that accessor is fine for one record
                // and quadratic for a catalogue.
                'below_minimum' => fn ($query, $value) => $query->whereRaw(
                    '(SELECT COALESCE(SUM(on_hand_quantity - on_hold_quantity), 0)
                        FROM inventories WHERE inventories.item_id = items.id) < items.minimum_stock',
                ),
                'created' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSearch(['name', 'sku', 'description'])
            ->allowSorts(['name', 'sku', 'unit_cost', 'created_at'])
            ->allowIncludes(['inventories', 'scrapSource'])
            ->defaultSort('name')
            ->paginate();

        return $this->respondPaginated(ItemResource::collection($items));
    }

    /**
     * Show an item
     *
     * Always carries the per-warehouse stock balances: a single item card is
     * exactly where they are wanted, and it costs one extra query.
     *
     * @authenticated
     *
     * @urlParam item integer required The item id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"item","name":"Copper Busbar 100x10","sku":"CU-BB-10010","unit_cost":"412.50","stock":[{"warehouse":{"value":"raw_materials","label":"Raw Materials","color":null},"on_hand":"120.0000","on_hold":"20.0000","available":"100.0000"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        return $this->respond(new ItemResource($item->load(['inventories', 'scrapSource'])));
    }

    /**
     * Create an item
     *
     * @authenticated
     *
     * @bodyParam name string required Item name. Example: Copper Busbar 100x10
     * @bodyParam sku string required Unique stock-keeping code. Example: CU-BB-10010
     * @bodyParam type string required One of the item_type enum values. Example: raw_material
     * @bodyParam unit string required One of the unit_of_measure enum values. Example: kg
     * @bodyParam unit_cost number required Standard cost per unit, in EGP. Example: 412.50
     * @bodyParam minimum_stock number optional Re-order threshold; 0 disables the low-stock alert. Example: 50
     * @bodyParam description string optional Free text. Example: Electrolytic copper
     *
     * @response 201 scenario="Created" {"data":{"id":9,"type":"item","name":"Copper Busbar 100x10","sku":"CU-BB-10010"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreItemRequest $request): JsonResponse
    {
        $this->authorize('create', Item::class);

        $item = Item::create($request->validated());

        return $this->respondCreated(new ItemResource($item));
    }

    /**
     * Update an item
     *
     * Send only the fields to change. `type` is editable, but changing it also
     * changes the item's home warehouse while existing stock rows stay where
     * they are, so only do it for an item with no movements yet.
     *
     * @authenticated
     *
     * @urlParam item integer required The item id. Example: 1
     * @bodyParam name string optional Example: Copper Busbar 100x12
     * @bodyParam sku string optional Must stay unique. Example: CU-BB-10012
     * @bodyParam type string optional Example: raw_material
     * @bodyParam unit string optional Example: kg
     * @bodyParam unit_cost number optional Example: 430.00
     * @bodyParam minimum_stock number optional Example: 60
     * @bodyParam description string optional Example: Electrolytic copper
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"item","name":"Copper Busbar 100x12"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $this->authorize('update', $item);

        $item->update($request->validated());

        return $this->respond(new ItemResource($item->fresh()));
    }

    /**
     * Delete an item
     *
     * A soft delete: the row stays so historical vouchers and BOM lines keep
     * resolving their item name. An item that still has stock on hand is
     * refused, because deleting it would strand a balance with nothing left to
     * report it against.
     *
     * @authenticated
     *
     * @urlParam item integer required The item id. Example: 1
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Still in stock" {"error":{"code":"business_rule_violated","message":"Cannot delete an item that still holds stock."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(Item $item): JsonResponse
    {
        $this->authorize('delete', $item);

        $onHand = (float) $item->inventories()->sum('on_hand_quantity');

        if ($onHand > 0) {
            throw new DomainException(__('errors.api.item_has_stock', ['quantity' => $onHand]));
        }

        $item->delete();

        return $this->respondNoContent();
    }
}
