<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Inventory;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item's balance in one warehouse.
 *
 * Three numbers, and the difference between them matters: `on_hand` is what is
 * physically there, `on_hold` is what has been reserved for an operation, and
 * `available` is what may still be promised. A client that showed `on_hand` as
 * "what we have" would let a planner commit material that is already spoken
 * for.
 *
 * @mixin Inventory
 */
class InventoryLevelResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'inventory_level',
            'item_id' => $this->item_id,
            'warehouse' => EnumPresenter::present($this->warehouse_type),

            'on_hand' => $this->quantity($this->on_hand_quantity),
            'on_hold' => $this->quantity($this->on_hold_quantity),
            'available' => $this->quantity($this->available_quantity),

            // On-hand valued at the item's current standard cost. Only when
            // the item is loaded, since it is the item that carries the price.
            'value' => $this->whenLoaded(
                'item',
                fn () => $this->item === null
                    ? null
                    : $this->money((float) $this->on_hand_quantity * (float) $this->item->unit_cost),
            ),

            'item' => $this->whenLoaded(
                'item',
                fn () => $this->item === null ? null : [
                    'id' => $this->item->id,
                    'name' => $this->item->name,
                    'sku' => $this->item->sku,
                    'unit' => EnumPresenter::present($this->item->unit),
                    'unit_cost' => $this->money($this->item->unit_cost),
                    'minimum_stock' => $this->quantity($this->item->minimum_stock),
                ],
            ),

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
