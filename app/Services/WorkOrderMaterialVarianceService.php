<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VoucherStatus;
use App\Models\Item;
use App\Models\IssueVoucherLine;
use App\Models\ReturnVoucherLine;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * مقارنة أمر التشغيل بأوامر الصرف — planned-vs-issued material comparison
 * (قائمة المواد.pptx سلايد 1). The order's material list says 15 kg of wood
 * are needed for five chairs; if the issue vouchers pulled 16 kg, that 1 kg is
 * the loss. Returned scrap (إذن ارتداد) is netted off the issued quantity so
 * material that came back does not read as a loss.
 *
 * Read-only: it derives everything from the order's material lines and its
 * posted vouchers, and never writes.
 */
class WorkOrderMaterialVarianceService
{
    /**
     * @return array{
     *     rows: Collection<int, array{item: ?Item, planned: float, issued: float, returned: float, net_issued: float, variance: float, unit_cost: float, variance_value: float, variance_percentage: ?float}>,
     *     planned_value: float,
     *     issued_value: float,
     *     variance_value: float
     * }
     */
    public function for(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing(['materials.item']);

        $planned = $workOrder->materials
            ->groupBy('item_id')
            ->map(fn (Collection $lines) => [
                'quantity' => (float) $lines->sum(fn ($line) => (float) $line->quantity),
                'unit_cost' => (float) $lines->first()->unit_cost,
                'item' => $lines->first()->item,
            ]);

        $issued = $this->issuedQuantities($workOrder);
        $returned = $this->returnedQuantities($workOrder);

        $itemIds = $planned->keys()
            ->merge($issued->keys())
            ->merge($returned->keys())
            ->unique()
            ->values();

        // Items that were issued without ever being planned still need a name.
        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        $plannedValue = 0.0;
        $issuedValue = 0.0;

        $rows = $itemIds->map(function ($itemId) use ($planned, $issued, $returned, $items, &$plannedValue, &$issuedValue): array {
            $plannedQty = (float) ($planned[$itemId]['quantity'] ?? 0);
            $issuedQty = (float) ($issued[$itemId]['quantity'] ?? 0);
            $returnedQty = (float) ($returned[$itemId] ?? 0);
            $netIssued = $issuedQty - $returnedQty;

            // Prefer the cost the store actually issued at; fall back to the
            // planned cost for items that were never issued.
            $unitCost = (float) ($issued[$itemId]['unit_cost'] ?? $planned[$itemId]['unit_cost'] ?? 0);
            $variance = round($netIssued - $plannedQty, 4);

            $plannedValue += $plannedQty * $unitCost;
            $issuedValue += $netIssued * $unitCost;

            return [
                'item' => $planned[$itemId]['item'] ?? $items[$itemId] ?? null,
                'planned' => round($plannedQty, 4),
                'issued' => round($issuedQty, 4),
                'returned' => round($returnedQty, 4),
                'net_issued' => round($netIssued, 4),
                'variance' => $variance,
                'unit_cost' => round($unitCost, 2),
                'variance_value' => round($variance * $unitCost, 2),
                'variance_percentage' => $plannedQty > 0 ? round(($variance / $plannedQty) * 100, 2) : null,
            ];
        })
            ->sortBy(fn (array $row) => $row['item']?->name ?? '')
            ->values();

        return [
            'rows' => $rows,
            'planned_value' => round($plannedValue, 2),
            'issued_value' => round($issuedValue, 2),
            'variance_value' => round($issuedValue - $plannedValue, 2),
        ];
    }

    /**
     * Quantity and unit cost actually issued per item, from posted issue
     * vouchers only — a draft voucher has not left the store yet.
     *
     * @return Collection<int, array{quantity: float, unit_cost: float}>
     */
    private function issuedQuantities(WorkOrder $workOrder): Collection
    {
        return IssueVoucherLine::query()
            ->whereHas('issueVoucher', fn ($q) => $q
                ->where('work_order_id', $workOrder->getKey())
                ->where('status', VoucherStatus::Posted->value))
            ->get()
            ->groupBy('item_id')
            ->map(fn (Collection $lines) => [
                'quantity' => (float) $lines->sum(fn ($line) => (float) $line->quantity),
                'unit_cost' => (float) $lines->last()->unit_cost,
            ]);
    }

    /**
     * Quantity returned to stock per item, from posted return vouchers.
     *
     * @return Collection<int, float>
     */
    private function returnedQuantities(WorkOrder $workOrder): Collection
    {
        return ReturnVoucherLine::query()
            ->whereHas('returnVoucher', fn ($q) => $q
                ->where('work_order_id', $workOrder->getKey())
                ->where('status', VoucherStatus::Posted->value))
            ->get()
            ->groupBy('item_id')
            ->map(fn (Collection $lines) => (float) $lines->sum(fn ($line) => (float) $line->quantity));
    }
}
