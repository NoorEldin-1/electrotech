<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Delivery;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\DeliveryVoucher;
use App\Models\DeliveryVoucherLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * إذن تسليم — goods leaving finished goods for a customer.
 *
 * The document turns on a **dual signature**. Technical management and
 * financial management sign independently, in either order, and the voucher
 * only becomes Active when both are on it. Activation is the moment everything
 * happens at once: the finished goods are deducted, the customer's account is
 * debited with the delivered value, and — if no work order is still open on
 * the operation — its cost centre is closed into cost of goods sold.
 *
 * Two derived blocks are read-only:
 *
 *  - **`approvals`** records who signed and when. Neither signature is a field
 *    a client writes; each has its own endpoint and its own permission,
 *    because a single person holding both would defeat the point of having
 *    two.
 *
 *  - **`invoicing`** is re-derived from the voucher's sales invoices on every
 *    change. Record an invoice to move it; never write the status.
 *
 * `total_value` is likewise derived, at activation, from the lines. Until the
 * voucher activates it is zero — the goods have not been delivered, so there
 * is no delivered value.
 *
 * @mixin DeliveryVoucher
 */
class DeliveryVoucherResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'delivery_voucher',
            'voucher_number' => $this->voucher_number,
            'status' => EnumPresenter::present($this->status),
            'voucher_date' => $this->voucher_date?->toDateString(),

            'customer_id' => $this->customer_id,
            'project_id' => $this->project_id,
            'supply_order_number' => $this->supply_order_number,

            'plates_count' => $this->plates_count,
            'protection_degree' => $this->protection_degree,
            'insulation_voltage' => $this->insulation_voltage,

            // Set at activation from the lines. Zero until then, because
            // nothing has been delivered yet.
            'total_value' => $this->money($this->total_value),

            // What the lines currently price out to, whatever the voucher's
            // state. Published so a draft can show a figure without a client
            // re-deriving one that might disagree with the eventual total.
            'lines_value' => $this->money($this->linesValue()),

            'invoicing' => [
                'status' => EnumPresenter::present($this->invoicing_status),
                'invoiced_value' => $this->money($this->invoiced_value),
                'non_invoice_reason' => $this->non_invoice_reason,
                'fully_invoiced' => $this->isFullyInvoiced(),
            ],

            'approvals' => [
                'technical_approved' => $this->isTechnicalApproved(),
                'technical_approved_by' => $this->technical_approved_by,
                'technical_approved_at' => $this->technical_approved_at?->toIso8601String(),
                'financial_approved' => $this->isFinancialApproved(),
                'financial_approved_by' => $this->financial_approved_by,
                'financial_approved_at' => $this->financial_approved_at?->toIso8601String(),
                'fully_approved' => $this->isFullyApproved(),
            ],

            'activated_at' => $this->activated_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),

            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn (DeliveryVoucherLine $line) => [
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
            'invoices_count' => $this->whenCounted('invoices'),
        ];
    }

    /**
     * What the voucher's lines price out to.
     *
     * The model's accessor sums the lines in PHP, which lazy-loads a relation
     * per row on a list page. The index attaches `lines_value_sum` as one
     * subquery instead, and the presence of that ATTRIBUTE — not its value —
     * decides which source to read: a voucher with no lines sums to SQL NULL,
     * and a null-coalesce would fall back to the accessor on exactly those
     * rows, reintroducing the N+1 (Finding #15).
     */
    private function linesValue(): float
    {
        return array_key_exists('lines_value_sum', $this->resource->getAttributes())
            ? (float) $this->resource->getAttribute('lines_value_sum')
            : (float) $this->lines_value;
    }
}
