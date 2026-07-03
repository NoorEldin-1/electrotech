<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\ReturnVoucher;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * إذن ارتداد — the exact inverse of IssueVoucherService. Returns the
 * unconsumed material from work-in-progress back to the SAME item's raw
 * warehouse (وارد على كرت الخامات) and REVERSES its value off the operation.
 *
 * The issue voucher transfers raw → WIP; the return voucher transfers WIP →
 * raw for the very same item, so the material re-enters stock under its own
 * code (no separate scrap item) and can be re-issued later.
 */
class ReturnVoucherService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Build a DRAFT return voucher for a work order, pre-filled with the
     * materials that were issued to it (quantity 0, for the warehouse to set
     * the actual returned amounts and drop the rest). No stock moves yet.
     */
    public function createFromWorkOrder(WorkOrder $workOrder): ReturnVoucher
    {
        return DB::transaction(function () use ($workOrder) {
            $voucher = ReturnVoucher::create([
                'voucher_number' => ReturnVoucher::generateVoucherNumber(),
                'work_order_id' => $workOrder->id,
                'voucher_date' => now(),
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
     * Post a return voucher: for every line with a positive quantity, transfer
     * the material out of the item's work-in-progress balance back into its raw
     * stock (same item, same code) and reverse its value off the operation
     * (project + work order). Lines left at zero are ignored. Idempotent by
     * status.
     *
     * @throws \RuntimeException if already posted, has no postable lines, or
     *                           WIP stock is short
     */
    public function post(ReturnVoucher $voucher): void
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

                // Return the unconsumed material from work-in-progress back to
                // the SAME item's raw stock — one atomic transfer, the exact
                // inverse of the issue voucher. It lands as وارد on the item's
                // raw stock card and leaves WIP (صادر there).
                $this->inventoryService->transferStock(
                    item: $line->item,
                    quantity: $qty,
                    from: WarehouseType::WorkInProgress,
                    to: WarehouseType::RawMaterials,
                    reference: $voucher,
                    notes: "Return voucher {$voucher->voucher_number} for WO #{$voucher->workOrder->wo_number}",
                    unitCost: $unitCost,
                );

                $total += $qty * $unitCost;
            }

            // Reverse the value off the operation — returned material is NOT
            // loaded on it.
            if ($project = $voucher->workOrder->project) {
                $project->decrement('actual_cost', $total);
            }

            $voucher->workOrder->decrement('actual_material_cost', $total);

            $voucher->update([
                'status' => VoucherStatus::Posted,
                'total_value' => $total,
                'signed_by' => Auth::id(),
                'signed_at' => now(),
            ]);
        });
    }
}
