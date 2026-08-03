<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Exceptions\ExcessIssueException;
use App\Models\BomItem;
use App\Models\IssueVoucher;
use App\Models\IssueVoucherLine;
use App\Models\Item;
use App\Models\ReturnVoucherLine;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
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
     * compatibility.
     *
     * The quantities are what the order STILL NEEDS, not its full plan: an
     * order issued in two batches must not have the second voucher propose the
     * whole recipe again. No stock moves yet — the warehouse reviews and posts.
     *
     * @throws \RuntimeException if the order has no materials, or nothing is left to issue
     */
    public function createFromWorkOrder(WorkOrder $workOrder): IssueVoucher
    {
        $workOrder->loadMissing(['materials.item', 'bom.items.item']);

        $lines = $this->suggestedLinesFor($workOrder);

        if ($lines === []) {
            throw new \RuntimeException(
                $this->plannedQuantities($workOrder)->isEmpty()
                    ? __('errors.issue.no_materials', ['number' => $workOrder->wo_number])
                    : __('errors.issue.nothing_remaining', ['number' => $workOrder->wo_number])
            );
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
     * The lines to pre-fill an issue voucher with for a work order: every item
     * the order still needs, at the quantity still owed and the current cost.
     *
     * This is what the "أمر التصنيع" select on the voucher form fills in — one
     * pass over three cheap aggregate queries, so re-picking the order several
     * times stays instant.
     *
     * @param  IssueVoucher|null  $excluding  the voucher being edited (its own lines must not count against it)
     * @return array<int, array{item_id:int, quantity:float, unit_cost:float}>
     */
    public function suggestedLinesFor(WorkOrder $workOrder, ?IssueVoucher $excluding = null): array
    {
        return $this->requirementFor($workOrder, $excluding, includeDrafts: true)
            ->filter(fn (array $row) => $row['remaining'] > 0)
            ->map(fn (array $row) => [
                'item_id' => $row['item_id'],
                'quantity' => $row['remaining'],
                'unit_cost' => $row['unit_cost'],
            ])
            ->values()
            ->all();
    }

    /**
     * What the work order still needs, per item.
     *
     * required          — the order's material plan (خامات أمر التصنيع), or its
     *                     BOM for legacy orders that never got a material table
     * previously_issued — issued on OTHER vouchers, net of posted returns
     * remaining         — required − previously_issued, floored at zero
     *
     * @param  bool  $includeDrafts  count other DRAFT vouchers as already issued
     *                               (right for a suggestion, wrong for the
     *                               posting gate, where only posted stock has
     *                               actually left the store)
     * @return Collection<int, array{item_id:int, item:?Item, required:float, previously_issued:float, remaining:float, unit_cost:float}>
     */
    public function requirementFor(
        WorkOrder $workOrder,
        ?IssueVoucher $excluding = null,
        bool $includeDrafts = false,
    ): Collection {
        $planned = $this->plannedQuantities($workOrder);
        $issued = $this->issuedQuantities($workOrder, $excluding?->getKey(), $includeDrafts);
        $returned = $this->returnedQuantities($workOrder);

        $itemIds = $planned->keys()->merge($issued->keys())->unique()->values();
        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        return $itemIds->mapWithKeys(function ($itemId) use ($planned, $issued, $returned, $items) {
            $required = (float) ($planned[$itemId]['quantity'] ?? 0);
            $net = (float) ($issued[$itemId] ?? 0) - (float) ($returned[$itemId] ?? 0);
            $item = $items[$itemId] ?? null;

            return [$itemId => [
                'item_id' => (int) $itemId,
                'item' => $item,
                'item_name' => $item?->name ?? '—',
                'required' => round($required, 4),
                'previously_issued' => round(max(0, $net), 4),
                'remaining' => round(max(0, $required - $net), 4),
                // Prefer the price the plan was costed at; fall back to the
                // item card so a never-planned item still carries a value.
                'unit_cost' => round((float) ($planned[$itemId]['unit_cost'] ?? $item?->unit_cost ?? 0), 2),
            ]];
        });
    }

    /**
     * Compare a draft voucher's lines against what the work order still needs
     * and return only the items it goes over on.
     *
     * Only POSTED vouchers count as already-issued here: the question at the
     * posting gate is "will this movement take the order past its plan?", and
     * a sibling draft has not moved anything yet.
     *
     * @return array<int, array{item_id:int, item_name:string, required:float, previously_issued:float, remaining:float, this_voucher:float, excess:float}>
     */
    public function excessReport(IssueVoucher $voucher): array
    {
        $voucher->loadMissing(['lines.item', 'workOrder.materials.item', 'workOrder.bom.items.item']);

        if (! $voucher->workOrder) {
            return [];
        }

        $requirement = $this->requirementFor($voucher->workOrder, $voucher, includeDrafts: false);

        // A work order with no plan at all cannot be compared against one —
        // there is nothing to be "over".
        if ($requirement->isEmpty()) {
            return [];
        }

        $onThisVoucher = $voucher->lines
            ->groupBy('item_id')
            ->map(fn (Collection $lines) => (float) $lines->sum(fn ($line) => (float) $line->quantity));

        $rows = [];

        foreach ($onThisVoucher as $itemId => $quantity) {
            $row = $requirement->get($itemId);
            $remaining = (float) ($row['remaining'] ?? 0);
            $excess = round($quantity - $remaining, 4);

            if ($excess <= 0) {
                continue;
            }

            $rows[] = [
                'item_id' => (int) $itemId,
                'item_name' => $row['item_name']
                    ?? $voucher->lines->firstWhere('item_id', $itemId)?->item?->name
                    ?? '—',
                'required' => (float) ($row['required'] ?? 0),
                'previously_issued' => (float) ($row['previously_issued'] ?? 0),
                'remaining' => $remaining,
                'this_voucher' => round($quantity, 4),
                'excess' => $excess,
            ];
        }

        return $rows;
    }

    /**
     * Post an issue voucher: transfer every line from raw materials into
     * work-in-progress and load the total value onto the operation
     * (project = cost centre). Idempotent by status.
     *
     * Before any stock moves, the voucher is compared against the work order's
     * material plan. Going over is allowed — a broken part has to be replaced —
     * but only as a decision: `$allowExcess` is passed by the UI *after* the
     * user with `issue_vouchers.approve_excess` has seen the offending rows and
     * written a reason, both of which are stamped on the document.
     *
     * @throws ExcessIssueException if the voucher exceeds the plan and the excess was not approved
     * @throws \RuntimeException if already posted, empty, or stock is short
     */
    public function post(IssueVoucher $voucher, bool $allowExcess = false, ?string $excessReason = null): void
    {
        if ($voucher->isPosted()) {
            throw new \RuntimeException(__('errors.voucher.already_posted', ['number' => $voucher->voucher_number]));
        }

        $voucher->loadMissing(['lines.item', 'workOrder.project', 'workOrder.materials']);

        if ($voucher->lines->isEmpty()) {
            throw new \RuntimeException(__('errors.voucher.no_lines', ['number' => $voucher->voucher_number]));
        }

        $excess = $this->excessReport($voucher);

        if ($excess !== [] && ! $allowExcess) {
            throw new ExcessIssueException($excess, __('errors.issue.excess_quantity', [
                'number' => $voucher->voucher_number,
            ]));
        }

        DB::transaction(function () use ($voucher, $excess, $excessReason) {
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

            $voucher->update(array_merge([
                'status' => VoucherStatus::Posted,
                'total_value' => $total,
                'signed_by' => Auth::id(),
                'signed_at' => now(),
            ], $excess === [] ? [] : [
                'has_excess' => true,
                'excess_reason' => $excessReason,
                'excess_approved_by' => Auth::id(),
                'excess_approved_at' => now(),
            ]));
        });
    }

    /**
     * The work order's material plan per item: its own material table first,
     * the linked BOM as the legacy fallback.
     *
     * @return Collection<int, array{quantity: float, unit_cost: float}>
     */
    private function plannedQuantities(WorkOrder $workOrder): Collection
    {
        $workOrder->loadMissing(['materials.item', 'bom.items.item']);

        if ($workOrder->materials->isNotEmpty()) {
            return $workOrder->materials
                ->groupBy('item_id')
                ->map(fn (Collection $lines) => [
                    'quantity' => (float) $lines->sum(fn ($line) => (float) $line->quantity),
                    'unit_cost' => (float) $lines->first()->unit_cost,
                ]);
        }

        if ($workOrder->bom) {
            return $workOrder->bom->items
                ->groupBy('item_id')
                ->map(fn (Collection $lines) => [
                    'quantity' => (float) $lines->sum(fn (BomItem $bomItem) => (float) $bomItem->total_required_quantity),
                    'unit_cost' => (float) ($lines->first()->item->unit_cost ?? 0),
                ]);
        }

        return collect();
    }

    /**
     * Quantity already issued for the order, per item.
     *
     * @return Collection<int, float>
     */
    private function issuedQuantities(WorkOrder $workOrder, ?int $excludeVoucherId, bool $includeDrafts): Collection
    {
        return IssueVoucherLine::query()
            ->whereHas('issueVoucher', function ($query) use ($workOrder, $excludeVoucherId, $includeDrafts) {
                $query->where('work_order_id', $workOrder->getKey());

                if (! $includeDrafts) {
                    $query->where('status', VoucherStatus::Posted->value);
                } else {
                    $query->whereIn('status', [VoucherStatus::Posted->value, VoucherStatus::Draft->value]);
                }

                if ($excludeVoucherId !== null) {
                    $query->whereKeyNot($excludeVoucherId);
                }
            })
            ->selectRaw('item_id, SUM(quantity) AS qty')
            ->groupBy('item_id')
            ->pluck('qty', 'item_id')
            ->map(fn ($qty) => (float) $qty);
    }

    /**
     * Quantity returned to the store for the order, per item — material that
     * came back is not material the order consumed.
     *
     * @return Collection<int, float>
     */
    private function returnedQuantities(WorkOrder $workOrder): Collection
    {
        return ReturnVoucherLine::query()
            ->whereHas('returnVoucher', fn ($query) => $query
                ->where('work_order_id', $workOrder->getKey())
                ->where('status', VoucherStatus::Posted->value))
            ->selectRaw('item_id, SUM(quantity) AS qty')
            ->groupBy('item_id')
            ->pluck('qty', 'item_id')
            ->map(fn ($qty) => (float) $qty);
    }
}
