<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Enums\LossType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\Account;
use App\Models\DepreciationVoucher;
use App\Models\JournalEntry;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * إذن إهلاك — writes manufacturing loss (هالك) out of work-in-progress and
 * carries it to a loss account (التصنيع سلايدات 5–6). Deducting the quantity
 * lowers the item's balance and value on its item card; a balanced journal
 * entry transfers the value to the loss account.
 *
 * The loss_type decides the operation-cost treatment (no double counting in the
 * Operation Cost Center: its journal is NOT tagged to the operation, so it never
 * lands in ledgerExpenses):
 *   - abnormal (غير طبيعي): reversed off the operation (decrement project +
 *     work order) → Dr manufacturing-loss account / Cr inventory.
 *   - natural (طبيعي): kept loaded on the operation (already there via the issue
 *     voucher) → Dr operating expenses / Cr inventory.
 */
class DepreciationVoucherService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly JournalEntryService $journals,
    ) {}

    /**
     * Build a DRAFT depreciation voucher for a work order, pre-filled with the
     * materials that were issued to it (quantity 0, for the user to set the
     * loss amounts and drop the rest). No stock moves yet.
     */
    public function createFromWorkOrder(WorkOrder $workOrder): DepreciationVoucher
    {
        return DB::transaction(function () use ($workOrder) {
            $voucher = DepreciationVoucher::create([
                'voucher_number' => DepreciationVoucher::generateVoucherNumber(),
                'work_order_id' => $workOrder->id,
                'voucher_date' => now(),
                'loss_type' => LossType::Abnormal,
                'status' => VoucherStatus::Draft,
                'issued_by' => Auth::id(),
            ]);

            $issuedLines = $workOrder->issueVouchers()
                ->where('status', VoucherStatus::Posted->value)
                ->with('lines.item')
                ->get()
                ->flatMap(fn ($iv) => $iv->lines)
                ->reject(fn ($line) => $line->item?->is_scrap)
                ->unique('item_id');

            foreach ($issuedLines as $line) {
                $voucher->lines()->create([
                    'item_id' => $line->item_id,
                    'quantity' => 0,
                    'unit_cost' => (float) ($line->item->unit_cost ?? 0),
                ]);
            }

            return $voucher;
        });
    }

    /**
     * Post a depreciation voucher: for every line with a positive quantity, take
     * the loss out of the item's WIP balance (lowering its item-card value),
     * carry the value to a loss account with a balanced journal entry, and — for
     * abnormal loss only — reverse the value off the operation. Lines left at
     * zero are ignored. Idempotent by status.
     *
     * @throws \RuntimeException if already posted, has no postable lines, or WIP
     *                           stock is short
     */
    public function post(DepreciationVoucher $voucher): void
    {
        if ($voucher->isPosted()) {
            throw new \RuntimeException(__('errors.voucher.already_posted', ['number' => $voucher->voucher_number]));
        }

        $voucher->loadMissing(['lines.item', 'workOrder.project']);

        $postable = $voucher->lines->filter(fn ($line) => (float) $line->quantity > 0);

        if ($postable->isEmpty()) {
            throw new \RuntimeException(__('errors.voucher.no_lines', ['number' => $voucher->voucher_number]));
        }

        DB::transaction(function () use ($voucher, $postable) {
            $total = 0.0;

            foreach ($postable as $line) {
                $qty = (float) $line->quantity;
                $unitCost = (float) $line->unit_cost;

                // Take the loss out of the item's WIP balance — this lowers its
                // quantity and value on the item card (سلايد 6: تخفيض قيمة المخزون).
                $this->inventoryService->deductStock(
                    item: $line->item,
                    quantity: $qty,
                    reference: $voucher,
                    notes: "Depreciation voucher {$voucher->voucher_number} for WO #{$voucher->workOrder->wo_number}",
                    warehouse: WarehouseType::WorkInProgress,
                    unitCost: $unitCost,
                );

                $total += $qty * $unitCost;
            }

            // Abnormal loss cannot be loaded on the operation (سلايد 6) — reverse
            // its value off the project and work order. Natural loss stays loaded
            // (سلايد 5: يُحمَّل على العملية) via the original issue voucher.
            if ($voucher->loss_type === LossType::Abnormal) {
                if ($project = $voucher->workOrder->project) {
                    $project->decrement('actual_cost', $total);
                }

                $voucher->workOrder->decrement('actual_material_cost', $total);
            }

            $entry = $this->postLossJournal($voucher, $total);

            $voucher->update([
                'status' => VoucherStatus::Posted,
                'total_value' => $total,
                'journal_entry_id' => $entry?->id,
                'signed_by' => Auth::id(),
                'signed_at' => now(),
            ]);
        });
    }

    /**
     * Carry the written-off value to the general ledger: Dr loss account / Cr
     * inventory. The loss account follows the loss type (abnormal → the
     * manufacturing-loss account, natural → operating expenses). Lines are NOT
     * tagged to the operation, so the write-off never double counts in the
     * Operation Cost Center. Skips silently if the accounts are not in the chart
     * (same philosophy as OperationPaymentService), leaving the stock move intact.
     */
    private function postLossJournal(DepreciationVoucher $voucher, float $total): ?JournalEntry
    {
        if ($total <= 0.0) {
            return null;
        }

        $inventory = Account::where('code', config('operations.inventory_account_code', '1300'))->first();

        $lossCode = $voucher->loss_type === LossType::Abnormal
            ? config('operations.abnormal_loss_account_code', '5060')
            : config('operations.natural_loss_account_code', '5010');

        $loss = Account::where('code', $lossCode)->first();

        if ($inventory === null || $loss === null) {
            return null;
        }

        $entry = JournalEntry::create([
            'entry_number' => JournalEntry::generateEntryNumber(DocumentType::Settlement),
            'document_type' => DocumentType::Settlement,
            'document_number' => $voucher->voucher_number,
            'entry_date' => $voucher->voucher_date ?? now(),
            'description' => __('resources.depreciation_vouchers.journal_description', [
                'number' => $voucher->voucher_number,
                'wo' => $voucher->workOrder->wo_number,
            ]),
            'status' => JournalStatus::Draft,
            'currency' => 'EGP',
            'created_by' => Auth::id(),
        ]);

        foreach ([
            [$loss->id, AccountDirection::Debit],
            [$inventory->id, AccountDirection::Credit],
        ] as [$accountId, $direction]) {
            $entry->lines()->create([
                'account_id' => $accountId,
                'project_id' => null,
                'direction' => $direction,
                'amount' => $total,
            ]);
        }

        $this->journals->post($entry->fresh('lines'));

        return $entry;
    }
}
