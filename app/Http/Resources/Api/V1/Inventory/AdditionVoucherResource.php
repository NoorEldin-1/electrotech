<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Inventory;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\AdditionVoucher;
use App\Models\AdditionVoucherLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An addition voucher (إذن إضافة) — the single goods-receipt document.
 *
 * Posting one adds stock, credits the supplier, and closes any purchase order
 * it is linked to. Nothing else in the platform adds purchased stock, which is
 * why receiving against a PO returns one of these rather than the order.
 *
 * @mixin AdditionVoucher
 */
class AdditionVoucherResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'addition_voucher',
            'voucher_number' => $this->voucher_number,
            'status' => EnumPresenter::present($this->status),
            'voucher_date' => $this->voucher_date?->toDateString(),

            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,

            // A registered supplier is optional (a receipt with no invoice or
            // order may carry only a free-text name), so the label resolves
            // the two and is what a client should display.
            'supplier_label' => $this->supplier_label,

            'purchase_order_id' => $this->purchase_order_id,

            // The invoicing state is DERIVED on every save from whether an
            // invoice number is present and whether the voucher was closed
            // without one. Treat it as read-only: change it by recording an
            // invoice or closing the voucher, never by writing the field.
            'invoicing_status' => EnumPresenter::present($this->invoicing_status),
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'invoice_value' => $this->money($this->invoice_value),

            // What actually entered the store, at cost. The reconciliation rule
            // is that this and `invoice_value` should agree.
            'received_value' => $this->money($this->received_value),

            // The signed difference when they do not, or null when there is
            // nothing to compare. Published so a client can flag the mismatch
            // without re-implementing the tolerance.
            'invoice_value_mismatch' => $this->when(
                true,
                fn () => $this->money($this->invoiceValueMismatch()),
            ),

            'closure_reason' => $this->closure_reason,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'supplier' => $this->whenLoaded(
                'supplier',
                fn () => $this->supplier === null ? null : [
                    'id' => $this->supplier->id,
                    'name' => $this->supplier->name,
                ],
            ),

            'purchase_order' => $this->whenLoaded(
                'purchaseOrder',
                fn () => $this->purchaseOrder === null ? null : [
                    'id' => $this->purchaseOrder->id,
                    'po_number' => $this->purchaseOrder->po_number,
                    'status' => EnumPresenter::present($this->purchaseOrder->status),
                ],
            ),

            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn (AdditionVoucherLine $line) => [
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
                    'unit_cost' => $this->money($line->unit_cost),
                    'line_value' => $this->money((float) $line->quantity * (float) $line->unit_cost),
                ])
                ->values()
                ->all()),

            'lines_count' => $this->whenCounted('lines'),
        ];
    }
}
