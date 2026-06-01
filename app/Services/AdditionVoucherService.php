<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\VoucherStatus;
use App\Models\AccountEntry;
use App\Models\AdditionVoucher;
use App\Models\AdditionVoucherLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdditionVoucherService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Post an addition voucher (إذن إضافة): add every line to its warehouse
     * and credit the supplier's account with the invoice value. Idempotent
     * by status — a posted voucher cannot be posted again.
     *
     * @throws \RuntimeException if already posted or has no lines
     */
    public function post(AdditionVoucher $voucher): void
    {
        if ($voucher->isPosted()) {
            throw new \RuntimeException(__('errors.voucher.already_posted', ['number' => $voucher->voucher_number]));
        }

        $voucher->loadMissing(['lines.item', 'supplier', 'purchaseOrder.project']);

        if ($voucher->lines->isEmpty()) {
            throw new \RuntimeException(__('errors.voucher.no_lines', ['number' => $voucher->voucher_number]));
        }

        DB::transaction(function () use ($voucher) {
            foreach ($voucher->lines as $line) {
                /** @var AdditionVoucherLine $line */
                $this->inventoryService->addStock(
                    item: $line->item,
                    quantity: (float) $line->quantity,
                    reference: $voucher,
                    notes: "Addition voucher {$voucher->voucher_number}",
                    warehouse: $line->item->homeWarehouse(),
                    unitCost: (float) $line->unit_cost,
                );
            }

            $amount = (float) $voucher->invoice_value;
            if ($amount <= 0) {
                $amount = $voucher->lines_value;
            }

            AccountEntry::create([
                'party_type' => $voucher->supplier->getMorphClass(),
                'party_id' => $voucher->supplier_id,
                'entry_date' => $voucher->voucher_date,
                'direction' => AccountDirection::Credit,
                'amount' => $amount,
                'reference_type' => $voucher->getMorphClass(),
                'reference_id' => $voucher->id,
                'operation_name' => $voucher->purchaseOrder?->project?->name,
                'notes' => $voucher->invoice_number ? "Invoice #{$voucher->invoice_number}" : null,
                'created_by' => Auth::id(),
            ]);

            $voucher->update([
                'status' => VoucherStatus::Posted,
                'posted_by' => Auth::id(),
                'posted_at' => now(),
            ]);
        });
    }
}
