<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BomItem;
use App\Models\Item;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Builds and syncs a work order's material lines from the finished good's
 * standard BOM (سلايد 6 — قائمة المواد التلقائية). The returned quantities are
 * scaled by the order's planned quantity; the office may then edit them freely
 * (المرونة والتعديل اليدوي) without touching the standard recipe.
 */
class WorkOrderMaterialService
{
    /**
     * Compute standard material lines for a work order from its output item's
     * latest approved standard BOM. Pure — returns an array of line arrays
     * (item_id, quantity, unit_cost, is_manual=false) without persisting.
     *
     * Quantity = BOM line total (incl. waste allowance) × planned quantity
     * (min 1 so a zero-planned order still fetches the base recipe).
     *
     * @return array<int, array{item_id:int, quantity:float, unit_cost:float, is_manual:bool}>
     *
     * @throws \RuntimeException if the output item has no approved standard BOM
     */
    public function fetchStandardMaterials(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing('outputItem');

        if (! $workOrder->outputItem) {
            throw new \RuntimeException(__('errors.work_order.no_output_item', ['number' => $workOrder->wo_number]));
        }

        return $this->standardMaterialsFor($workOrder->outputItem, (float) $workOrder->planned_quantity);
    }

    /**
     * Core recipe expansion, decoupled from a persisted work order so the
     * Filament form can call it while the order is still being authored.
     *
     * @return array<int, array{item_id:int, quantity:float, unit_cost:float, is_manual:bool}>
     *
     * @throws \RuntimeException if the item has no approved standard BOM
     */
    public function standardMaterialsFor(Item $outputItem, float $plannedQuantity): array
    {
        $bom = $outputItem->latestApprovedStandardBom();

        if (! $bom) {
            throw new \RuntimeException(__('errors.work_order.no_standard_bom', [
                'item' => $outputItem->name,
            ]));
        }

        $bom->loadMissing('items.item');

        $multiplier = max(1.0, $plannedQuantity);

        return $bom->items->map(fn (BomItem $bomItem) => [
            'item_id' => $bomItem->item_id,
            'quantity' => (float) $bomItem->total_required_quantity * $multiplier,
            'unit_cost' => (float) ($bomItem->item->unit_cost ?? 0),
            'is_manual' => false,
        ])->all();
    }

    /**
     * Replace a work order's material lines with the given set, atomically.
     * Used by the "re-fetch standard materials" action.
     *
     * @param  array<int, array{item_id:int, quantity:float, unit_cost:float, is_manual?:bool}>  $lines
     */
    public function syncMaterials(WorkOrder $workOrder, array $lines): void
    {
        DB::transaction(function () use ($workOrder, $lines) {
            $workOrder->materials()->delete();

            foreach ($lines as $line) {
                $workOrder->materials()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'is_manual' => $line['is_manual'] ?? false,
                    'notes' => $line['notes'] ?? null,
                ]);
            }
        });
    }
}
