<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Inventory;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One movement in the stock ledger.
 *
 * `type` carries four values and they are not interchangeable: `in` and `out`
 * change what is physically on hand, while `hold` and `release` only move
 * quantity between held and available. Anything that sums quantities to value
 * the stock must use `in`/`out` alone — folding the reservations in would
 * count the same material twice.
 *
 * @mixin InventoryTransaction
 */
class InventoryTransactionResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'inventory_transaction',
            'item_id' => $this->item_id,
            'movement' => EnumPresenter::present($this->type),
            'warehouse' => EnumPresenter::present($this->warehouse_type),
            'quantity' => $this->quantity($this->quantity),
            'unit_cost' => $this->money($this->unit_cost),
            'notes' => $this->notes,

            // The document that caused the movement — an addition voucher, an
            // issue voucher, a reservation. Published as type + id rather than
            // a nested object: the referenced models span most of the platform,
            // and loading each one to render a label would be a query per row.
            'reference' => $this->reference_type === null ? null : [
                'type' => class_basename((string) $this->reference_type),
                'id' => $this->reference_id,
            ],

            'performed_by' => $this->whenLoaded(
                'performedBy',
                fn () => $this->performedBy === null ? null : [
                    'id' => $this->performedBy->id,
                    'name' => $this->performedBy->name,
                ],
            ),

            'item' => $this->whenLoaded(
                'item',
                fn () => $this->item === null ? null : [
                    'id' => $this->item->id,
                    'name' => $this->item->name,
                    'sku' => $this->item->sku,
                ],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
