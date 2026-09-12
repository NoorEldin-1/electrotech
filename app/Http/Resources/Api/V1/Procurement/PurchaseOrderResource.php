<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Procurement;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A purchase order (أمر شراء).
 *
 * The money breakdown is unusual enough to be worth stating: the total is
 * `subtotal + VAT − profit tax`. The 1% profit-tax withholding is *deducted*,
 * not added, and is skipped entirely for a supplier holding an exemption. A
 * client that summed the three fields naively would show the supplier a figure
 * ~2% too high, so every component is published rather than left to be
 * re-derived.
 *
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'purchase_order',
            'po_number' => $this->po_number,
            'status' => EnumPresenter::present($this->status),

            // Nullable since the purchasing round that allowed warehouse
            // orders: an empty project means stock bought for the store rather
            // than against one operation.
            'project_id' => $this->project_id,

            'supplier_id' => $this->supplier_id,

            // Denormalised on the row and kept for orders raised before the
            // supplier file existed; the linked supplier wins when both are
            // present.
            'supplier_name' => $this->supplier_name,
            'supplier_contact' => $this->supplier_contact,

            'subtotal' => $this->money($this->subtotal),
            'vat_amount' => $this->money($this->vat_amount),
            'profit_tax_amount' => $this->money($this->profit_tax_amount),
            'apply_profit_tax' => (bool) $this->apply_profit_tax,
            'total_amount' => $this->money($this->total_amount),

            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded(
                'project',
                fn () => $this->project === null ? null : [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'code' => $this->project->code,
                ],
            ),

            'supplier' => $this->whenLoaded(
                'supplier',
                fn () => $this->supplier === null ? null : [
                    'id' => $this->supplier->id,
                    'name' => $this->supplier->name,
                    'profit_tax_exempt' => (bool) $this->supplier->profit_tax_exempt,
                ],
            ),

            'approved_by' => $this->whenLoaded(
                'approvedBy',
                fn () => $this->approvedBy === null ? null : [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                ],
            ),

            'items' => $this->whenLoaded('items', fn () => $this->items
                ->map(fn (PurchaseOrderItem $line) => [
                    'id' => $line->id,
                    'item_id' => $line->item_id,
                    'item' => $line->relationLoaded('item') && $line->item !== null
                        ? [
                            'id' => $line->item->id,
                            'name' => $line->item->name,
                            'sku' => $line->item->sku,
                            'unit' => EnumPresenter::present($line->item->unit),
                        ]
                        : null,

                    'quantity' => $this->quantity($line->quantity),
                    'unit_price' => $this->money($line->unit_price),
                    'line_total' => $this->money($line->line_total),

                    // What the receiving screen needs: how much has arrived and
                    // how much may still be received. `remaining_quantity` is
                    // the cap the receive endpoint enforces per line.
                    'received_quantity' => $this->quantity($line->received_quantity),
                    'remaining_quantity' => $this->quantity($line->remaining_quantity),
                    'is_fully_received' => $line->isFullyReceived(),
                ])
                ->values()
                ->all()),

            'items_count' => $this->whenCounted('items'),
        ];
    }
}
