<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Models\AccountEntry;
use App\Models\AdditionVoucher;
use App\Models\AdditionVoucherLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * مطابقة فواتير المشتريات مع أذون الإضافة (سلايد 11). The file's rule is that
 * total purchase invoices must equal total addition vouchers; this service
 * keeps every voucher's invoicing state current so the voucher list answers
 * one question — did this receipt's supplier invoice arrive, or was the
 * voucher closed without one, and why?
 *
 * Unlike sales, there is no separate invoice table: invoice_value is the very
 * amount AdditionVoucherService::post credits the supplier with, so a parallel
 * table would create a second source of truth for the same money.
 */
class PurchaseInvoicingService
{
    /**
     * Rounding tolerance (one piastre) when comparing money.
     */
    private const TOLERANCE = 0.01;

    /**
     * Record the supplier invoice on a goods receipt (مفوتر). Allowed both
     * before and after posting: the invoice may arrive before the goods or
     * long after them.
     *
     * Who recorded it is captured by the activity log's causer, so no user is
     * stored on the voucher itself.
     *
     * @param  array{invoice_number: string, invoice_date?: mixed, invoice_value?: mixed}  $data
     *
     * @throws \RuntimeException if the invoice value is negative
     */
    public function recordInvoice(AdditionVoucher $voucher, array $data): AdditionVoucher
    {
        $value = array_key_exists('invoice_value', $data) && $data['invoice_value'] !== null
            ? (float) $data['invoice_value']
            : (float) $voucher->invoice_value;

        if ($value < 0) {
            throw new \RuntimeException(__('errors.purchase_invoice.invalid_value'));
        }

        return DB::transaction(function () use ($voucher, $data, $value) {
            $voucher->update([
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'] ?? $voucher->invoice_date,
                'invoice_value' => $value,
                // An invoice always beats a closure: the file said this
                // receipt would never be invoiced, and it was wrong.
                'closure_reason' => null,
                'closed_at' => null,
                'closed_by' => null,
            ]);

            // The supplier was credited at posting time with an estimate (the
            // stock value) because the invoice had not arrived. Now that the
            // real value is known, correct that entry instead of adding a
            // second one — a new entry would double the supplier's balance.
            $this->syncSupplierEntry($voucher);

            return $voucher->refresh();
        });
    }

    /**
     * إقفال الإذن بدون فاتورة — the receipt will never be invoiced, and the
     * reason is written down. Only a posted voucher can be closed: a draft is
     * still editable and deletable, so closing it means nothing.
     *
     * @throws \RuntimeException if the voucher is not posted or already invoiced
     */
    public function closeWithoutInvoice(AdditionVoucher $voucher, string $reason, ?User $user = null): AdditionVoucher
    {
        if (! $voucher->isPosted()) {
            throw new \RuntimeException(__('errors.purchase_invoice.not_posted', ['number' => $voucher->voucher_number]));
        }

        if (filled($voucher->invoice_number)) {
            throw new \RuntimeException(__('errors.purchase_invoice.already_invoiced', [
                'number' => $voucher->voucher_number,
                'invoice' => $voucher->invoice_number,
            ]));
        }

        if (trim($reason) === '') {
            throw new \RuntimeException(__('errors.purchase_invoice.reason_required'));
        }

        $voucher->update([
            'closure_reason' => trim($reason),
            'closed_at' => now(),
            'closed_by' => $user?->id,
        ]);

        return $voucher->refresh();
    }

    /**
     * Undo a closure — the voucher goes back to awaiting its invoice.
     */
    public function reopen(AdditionVoucher $voucher): AdditionVoucher
    {
        $voucher->update([
            'closure_reason' => null,
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return $voucher->refresh();
    }

    /**
     * Re-derive received_value (Σ quantity × unit_cost) — the value that
     * physically entered the store, which the invoice must match.
     */
    public function recalculateReceivedValue(AdditionVoucher $voucher): void
    {
        $received = round((float) $voucher->lines()->get()->sum(
            fn (AdditionVoucherLine $line) => (float) $line->quantity * (float) $line->unit_cost
        ), 2);

        if (abs((float) $voucher->received_value - $received) > self::TOLERANCE) {
            $voucher->update(['received_value' => $received]);
        }
    }

    /**
     * The amount the supplier is credited with: the invoice value once it is
     * known, otherwise the stock value — the same rule
     * AdditionVoucherService::post applies.
     */
    public function supplierAmountFor(AdditionVoucher $voucher): float
    {
        $amount = (float) $voucher->invoice_value;

        return $amount > 0 ? $amount : (float) $voucher->received_value;
    }

    /**
     * Bring the supplier's ledger entry in line with the invoice value. Only
     * touches a posted voucher with a registered supplier — nothing was posted
     * to the ledger otherwise.
     */
    private function syncSupplierEntry(AdditionVoucher $voucher): void
    {
        if (! $voucher->isPosted() || $voucher->supplier_id === null) {
            return;
        }

        $entry = AccountEntry::query()
            ->where('reference_type', $voucher->getMorphClass())
            ->where('reference_id', $voucher->id)
            ->where('direction', AccountDirection::Credit)
            ->orderBy('id')
            ->first();

        if (! $entry instanceof AccountEntry) {
            return;
        }

        $amount = $this->supplierAmountFor($voucher);

        if (abs((float) $entry->amount - $amount) <= self::TOLERANCE) {
            // Only the invoice number changed — keep the note in step anyway.
            $entry->update(['notes' => "Invoice #{$voucher->invoice_number}"]);

            return;
        }

        $entry->update([
            'amount' => $amount,
            'notes' => "Invoice #{$voucher->invoice_number}",
        ]);
    }
}
