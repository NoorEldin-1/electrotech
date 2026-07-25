<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoicingStatus;
use App\Models\DeliveryVoucher;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * مطابقة فواتير المبيعات مع أذون التسليم (سلايد 10). The file's rule is that
 * total sales invoices must equal total delivery vouchers; this service
 * enforces it per voucher (no over-invoicing) and keeps every voucher's
 * invoicing status current so the voucher list answers "was this invoiced?".
 *
 * It deliberately writes NO accounting entry — see the SalesInvoice docblock.
 */
class SalesInvoicingService
{
    /**
     * Rounding tolerance (one piastre) when comparing money.
     */
    private const TOLERANCE = 0.01;

    /**
     * Record an invoice against a delivered (activated) voucher.
     *
     * @param  array{invoice_number: string, invoice_date: mixed, amount: mixed, notes?: ?string}  $data
     *
     * @throws \RuntimeException if the voucher is not active or the invoice
     *                           would exceed the delivered value
     */
    public function record(DeliveryVoucher $voucher, array $data, ?User $user = null): SalesInvoice
    {
        if (! $voucher->isActive()) {
            throw new \RuntimeException(__('errors.sales_invoice.voucher_not_active', ['number' => $voucher->voucher_number]));
        }

        $amount = (float) ($data['amount'] ?? 0);

        if ($amount > $this->remainingFor($voucher) + self::TOLERANCE) {
            throw new \RuntimeException(__('errors.sales_invoice.exceeds_voucher_value', [
                'number' => $voucher->voucher_number,
                'remaining' => number_format($this->remainingFor($voucher), 2),
            ]));
        }

        return DB::transaction(function () use ($voucher, $data, $user, $amount) {
            $invoice = $voucher->invoices()->create([
                'invoice_number' => $data['invoice_number'],
                'customer_id' => $voucher->customer_id,
                'invoice_date' => $data['invoice_date'],
                'amount' => $amount,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $this->recalculate($voucher);

            return $invoice;
        });
    }

    /**
     * Re-derive invoiced_value and invoicing_status from the voucher's
     * invoices. Called after any invoice is created, edited or deleted.
     */
    public function recalculate(DeliveryVoucher $voucher): void
    {
        $invoiced = round((float) $voucher->invoices()->sum('amount'), 2);
        $total = round((float) $voucher->total_value, 2);

        $status = match (true) {
            $invoiced <= 0.0 => InvoicingStatus::NotInvoiced,
            // A voucher with no value yet (not activated) cannot be "fully"
            // invoiced in any meaningful sense.
            $total <= 0.0 => InvoicingStatus::PartiallyInvoiced,
            $invoiced >= $total - self::TOLERANCE => InvoicingStatus::FullyInvoiced,
            default => InvoicingStatus::PartiallyInvoiced,
        };

        $voucher->update([
            'invoiced_value' => $invoiced,
            'invoicing_status' => $status,
        ]);
    }

    /**
     * The delivered value still awaiting an invoice.
     */
    public function remainingFor(DeliveryVoucher $voucher): float
    {
        $remaining = (float) $voucher->total_value - (float) $voucher->invoices()->sum('amount');

        return round(max($remaining, 0.0), 2);
    }
}
