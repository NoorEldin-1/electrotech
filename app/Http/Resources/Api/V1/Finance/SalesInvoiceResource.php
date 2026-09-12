<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * فاتورة مبيعات — an invoice raised against a delivered voucher.
 *
 * An invoice never stands alone: it belongs to a delivery that has actually
 * happened, and the sum of a voucher's invoices may not exceed what was
 * delivered. That is the rule that keeps "invoiced" and "delivered" reconcilable
 * — invoice a voucher in three instalments if you like, but not for more than
 * left the store.
 *
 * The customer is taken from the voucher rather than sent, so an invoice can
 * never name someone other than whoever received the goods.
 *
 * @mixin SalesInvoice
 */
class SalesInvoiceResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'sales_invoice',
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),

            'delivery_voucher_id' => $this->delivery_voucher_id,
            'customer_id' => $this->customer_id,
            'amount' => $this->money($this->amount),
            'notes' => $this->notes,
            'created_by' => $this->created_by,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'delivery_voucher' => $this->whenLoaded('deliveryVoucher', fn () => $this->deliveryVoucher === null ? null : [
                'id' => $this->deliveryVoucher->id,
                'voucher_number' => $this->deliveryVoucher->voucher_number,
                'total_value' => $this->money($this->deliveryVoucher->total_value),
                'invoiced_value' => $this->money($this->deliveryVoucher->invoiced_value),
            ]),

            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
        ];
    }
}
