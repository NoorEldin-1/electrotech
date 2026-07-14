<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\BomItem;
use App\Models\IssueVoucher;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IssueVoucherService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Build a DRAFT issue voucher for a work order. Lines come from the order's
     * own material table (سلايد 6 — the manually-adjustable خامات أمر التصنيع)
     * when present; otherwise it falls back to the linked BOM for backward
     * compatibility. No stock moves yet — the warehouse reviews and posts it.
     *
     * @throws \RuntimeException if the work order has neither materials nor a BOM
     */
    public function createFromWorkOrder(WorkOrder $workOrder): IssueVoucher
    {
        $workOrder->loadMissing(['materials.item', 'bom.items.item']);

        $lines = $this->resolveVoucherLines($workOrder);

        if ($lines === []) {
            throw new \RuntimeException(__('errors.issue.no_materials', ['number' => $workOrder->wo_number]));
        }

        return DB::transaction(function () use ($workOrder, $lines) {
            $voucher = IssueVoucher::create([
                'voucher_number' => IssueVoucher::generateVoucherNumber(),
                'work_order_id' => $workOrder->id,
                'voucher_date' => now(),
                'status' => VoucherStatus::Draft,
                'issued_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $voucher->lines()->create($line);
            }

            return $voucher;
        });
    }

    /**
     * Resolve the voucher lines: per-order materials first, BOM as fallback.
     *
     * @return array<int, array{item_id:int, quantity:float, unit_cost:float}>
     */
    private function resolveVoucherLines(WorkOrder $workOrder): array
    {
        if ($workOrder->materials->isNotEmpty()) {
            return $workOrder->materials
                ->map(fn ($material) => [
                    'item_id' => $material->item_id,
                    'quantity' => (float) $material->quantity,
                    'unit_cost' => (float) $material->unit_cost,
                ])->all();
        }

        if ($workOrder->bom) {
            return $workOrder->bom->items
                ->map(fn (BomItem $bomItem) => [
                    'item_id' => $bomItem->item_id,
                    'quantity' => (float) $bomItem->total_required_quantity,
                    'unit_cost' => (float) ($bomItem->item->unit_cost ?? 0),
                ])->all();
        }

        return [];
    }

    /**
     * Post an issue voucher: transfer every line from raw materials into
     * work-in-progress and load the total value onto the operation
     * (project = cost centre). Idempotent by status.
     *
     * @throws \RuntimeException if already posted, empty, or stock is short
     */
    public function post(IssueVoucher $voucher): void
    {
        if ($voucher->isPosted()) {
            throw new \RuntimeException(__('errors.voucher.already_posted', ['number' => $voucher->voucher_number]));
        }

        $voucher->loadMissing(['lines.item', 'workOrder.project']);

        if ($voucher->lines->isEmpty()) {
            throw new \RuntimeException(__('errors.voucher.no_lines', ['number' => $voucher->voucher_number]));
        }

        DB::transaction(function () use ($voucher) {
            $total = 0.0;

            foreach ($voucher->lines as $line) {
                $this->inventoryService->transferStock(
                    item: $line->item,
                    quantity: (float) $line->quantity,
                    from: WarehouseType::RawMaterials,
                    to: WarehouseType::WorkInProgress,
                    reference: $voucher,
                    notes: "Issue voucher {$voucher->voucher_number} for WO #{$voucher->workOrder->wo_number}",
                    unitCost: (float) $line->unit_cost,
                );

                $total += (float) $line->quantity * (float) $line->unit_cost;
            }

            // Load the issued value onto the operation (cost centre).
            if ($project = $voucher->workOrder->project) {
                $project->increment('actual_cost', $total);
            }

            // Accrue onto the work order too, for the estimate-vs-actual
            // comparison on the operating order (سلايد 2 المقارنة).
            $voucher->workOrder->increment('actual_material_cost', $total);

            $voucher->update([
                'status' => VoucherStatus::Posted,
                'total_value' => $total,
                'signed_by' => Auth::id(),
                'signed_at' => now(),
            ]);
        });
    }
}
