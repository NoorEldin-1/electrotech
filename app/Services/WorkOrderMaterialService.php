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
        $workOrder->loadMissing(['outputs.item', 'outputItem']);

        // Multi-product order (المنتجات التامة): merge every product's recipe.
        if ($workOrder->outputs->isNotEmpty()) {
            $result = $this->standardMaterialsForOutputs(
                $workOrder->outputs
                    ->map(fn ($output) => [
                        'item_id' => $output->item_id,
                        'planned_quantity' => (float) $output->planned_quantity,
                    ])
                    ->all()
            );

            if ($result['lines'] === []) {
                throw new \RuntimeException(__('errors.work_order.no_standard_bom', [
                    'item' => implode('، ', $result['missing']),
                ]));
            }

            return $result['lines'];
        }

        if (! $workOrder->outputItem) {
            throw new \RuntimeException(__('errors.work_order.no_output_item', ['number' => $workOrder->wo_number]));
        }

        return $this->standardMaterialsFor($workOrder->outputItem, (float) $workOrder->planned_quantity);
    }

    /**
     * Expand and MERGE the standard recipes of several finished products, each
     * scaled by its own planned quantity — the material plan of a mixed order
     * (three panels of model A + two of model B pull one combined list, with
     * shared raw materials summed into a single line so the store issues them
     * once).
     *
     * A product with no approved standard BOM does not sink the whole fetch:
     * its name comes back under `missing` so the form can name it, while the
     * rest of the order still gets its materials.
     *
     * @param  array<int, array{item_id: int|string|null, planned_quantity: float|int|string|null}>  $outputs
     * @return array{lines: array<int, array{item_id:int, quantity:float, unit_cost:float, is_manual:bool}>, missing: array<int, string>}
     */
    public function standardMaterialsForOutputs(array $outputs): array
    {
        /** @var array<int, array{item_id:int, quantity:float, unit_cost:float, is_manual:bool}> $merged */
        $merged = [];
        $missing = [];

        // One query for every product, instead of one per repeater row.
        $itemIds = collect($outputs)->pluck('item_id')->filter()->unique()->all();
        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        foreach ($outputs as $output) {
            $item = $items[$output['item_id'] ?? null] ?? null;
            $quantity = (float) ($output['planned_quantity'] ?? 0);

            // A row still being authored (product picked, quantity not typed
            // yet) contributes nothing rather than a phantom single unit.
            if (! $item || $quantity <= 0) {
                continue;
            }

            try {
                $lines = $this->standardMaterialsFor($item, $quantity);
            } catch (\RuntimeException) {
                $missing[] = $item->name;

                continue;
            }

            foreach ($lines as $line) {
                $key = $line['item_id'];

                if (isset($merged[$key])) {
                    $merged[$key]['quantity'] += $line['quantity'];

                    continue;
                }

                $merged[$key] = $line;
            }
        }

        return [
            'lines' => array_values($merged),
            'missing' => array_values(array_unique($missing)),
        ];
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
