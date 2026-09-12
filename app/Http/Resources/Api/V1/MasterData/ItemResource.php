<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\MasterData;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'item',
            'name' => $this->name,
            'sku' => $this->sku,
            'item_type' => EnumPresenter::present($this->type),
            'unit' => EnumPresenter::present($this->unit),

            'unit_cost' => $this->money($this->unit_cost),
            'minimum_stock' => $this->quantity($this->minimum_stock),
            'description' => $this->description,

            // Scrap variants (إذن ارتداد) are ordinary items pointing back at
            // the raw material they came from. The client needs the flag to
            // keep them out of pickers that should only offer real materials.
            'is_scrap' => (bool) $this->is_scrap,
            'scrap_source_item_id' => $this->scrap_source_item_id,

            // The warehouse this item's stock lives in by default, derived
            // from its type. Published so a mobile form can preselect it
            // instead of re-implementing WarehouseType::homeFor() in Dart.
            'home_warehouse' => EnumPresenter::present($this->homeWarehouse()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Per-warehouse balances, only when the caller asked for them via
            // `?include=inventories`. Left out by default because a catalog
            // page of 100 items would otherwise carry 300 stock rows nobody
            // reads on a mobile connection.
            'stock' => $this->whenLoaded('inventories', fn () => $this->inventories
                ->map(fn (Inventory $row) => [
                    'warehouse' => EnumPresenter::present($row->warehouse_type),
                    'on_hand' => $this->quantity($row->on_hand_quantity),
                    'on_hold' => $this->quantity($row->on_hold_quantity),
                    'available' => $this->quantity(
                        (float) $row->on_hand_quantity - (float) $row->on_hold_quantity,
                    ),
                ])
                ->values()
                ->all()),

            'scrap_source' => $this->whenLoaded(
                'scrapSource',
                fn () => $this->scrapSource === null ? null : [
                    'id' => $this->scrapSource->id,
                    'name' => $this->scrapSource->name,
                    'sku' => $this->scrapSource->sku,
                ],
            ),
        ];
    }
}
