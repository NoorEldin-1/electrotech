<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ReturnVoucher;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * إذن ارتداد — the inverse of IssueVoucherService. Returns unconsumed scrap
 * (فضلات) from work-in-progress to the raw warehouse under a different code
 * (the linked scrap variant item) and REVERSES its value off the operation.
 */
class ReturnVoucherService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Find or create the scrap variant for a raw item — a real stock item with
     * a derived SKU (`<sku>-SCRAP`), flagged `is_scrap`, that holds returned
     * material under a different code (سلايد 4). Idempotent and race-safe.
     */
    public function ensureScrapVariant(Item $original): Item
    {
        // An item flagged as scrap is its own variant.
        if ($original->is_scrap) {
            return $original;
        }

        if ($variant = $original->scrapVariant) {
            return $variant;
        }

        $sku = $original->sku . '-SCRAP';

        return Cache::lock('scrap_variant:item_' . $original->id, 10)->block(5, function () use ($original, $sku) {
            return DB::transaction(function () use ($original, $sku) {
                // Re-check under the lock in case a concurrent return created it.
                $existing = Item::where('scrap_source_item_id', $original->id)->first()
                    ?? Item::where('sku', $sku)->first();

                if ($existing) {
                    return $existing;
                }

                return Item::create([
                    'sku' => $sku,
                    'name' => $original->name . ' (' . __('resources.items.scrap_label') . ')',
                    'type' => ItemType::RawMaterial,
                    'is_scrap' => true,
                    'scrap_source_item_id' => $original->id,
                    'unit' => $original->unit,
                    'unit_cost' => $original->unit_cost,
                    'minimum_stock' => 0,
                ]);
            });
        });
    }

    /**
     * Build a DRAFT return voucher for a work order, pre-filled with the
     * materials that were issued to it (quantity 0, for the warehouse to set
     * the actual scrap amounts and drop the rest). No stock moves yet.
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
     * Post a return voucher: for every line with a positive quantity, take the
     * scrap out of the original item's WIP balance, add it to the scrap
     * variant's raw stock, and reverse its value off the operation (project +
     * work order). Lines left at zero are ignored. Idempotent by status.
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
                $scrap = $this->ensureScrapVariant($line->item);
                $qty = (float) $line->quantity;
                $unitCost = (float) $line->unit_cost;

                // Take the scrap out of the original item's WIP balance.
                $this->inventoryService->deductStock(
                    item: $line->item,
                    quantity: $qty,
                    reference: $voucher,
                    notes: "Return voucher {$voucher->voucher_number} for WO #{$voucher->workOrder->wo_number}",
                    warehouse: WarehouseType::WorkInProgress,
                    unitCost: $unitCost,
                );

                // Add it to the scrap variant's raw stock (the different code).
                $this->inventoryService->addStock(
                    item: $scrap,
                    quantity: $qty,
                    reference: $voucher,
                    notes: "Scrap return from WO #{$voucher->workOrder->wo_number} ({$voucher->voucher_number})",
                    warehouse: WarehouseType::RawMaterials,
                    unitCost: $unitCost,
                );

                $total += $qty * $unitCost;
            }

            // Reverse the value off the operation — scrap is NOT loaded on it.
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
