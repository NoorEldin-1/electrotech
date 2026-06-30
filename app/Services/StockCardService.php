<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Models\InventoryTransaction;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;

/**
 * كرت الصنف (Stock Card) — builds the perpetual moving weighted-average
 * ledger for an item in a single warehouse, exactly mirroring the manual
 * accounting card (المتوسط المرجح المتحرك):
 *
 *   وارد (In)  : value = qty × purchase price; the new balance average is
 *                recomputed as (running value ÷ running qty).
 *   صادر (Out) : leaves at the CURRENT balance average ("يخرج بآخر سعر
 *                موجود"); the average itself is unchanged by an issue.
 *   الرصيد      : running quantity, average unit cost, and stock value.
 *   الإجمالي    : the last balance row (qty × avg = stock value on hand).
 *
 * This is a PURE READ over the append-only `inventory_transactions` ledger.
 * It never writes and never touches InventoryService's locked write path, so
 * it adds the report without any risk to the live stock balances. The average
 * is therefore always consistent with the ledger (single source of truth).
 *
 * Only In/Out movements carry quantity value; Hold/Release are reservations
 * (محجوز/مُفرَج) and are excluded — they change availability, not stock value.
 * Scrap variants are NOT folded in: merging two distinct items into one
 * average pool would be accounting-incorrect, so the card is per-item.
 */
class StockCardService
{
    /**
     * Float tolerance below which a running quantity is treated as exactly
     * zero, so accumulated rounding drift can never push a fully-issued
     * balance slightly negative (and corrupt the next average).
     */
    private const EPSILON = 1e-9;

    /**
     * @return array{
     *     warehouse: WarehouseType,
     *     rows: list<array<string, mixed>>,
     *     totals: array{quantity: float, unit_cost: float, value: float}
     * }
     */
    public function build(Item $item, ?WarehouseType $warehouse = null): array
    {
        $warehouse ??= $item->homeWarehouse();

        $movements = $item->inventoryTransactions()
            ->where('warehouse_type', $warehouse->value)
            ->whereIn('type', [TransactionType::In->value, TransactionType::Out->value])
            ->with(['performedBy:id,name', 'reference'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rows = [];
        $balanceQty = 0.0;
        $balanceValue = 0.0;

        foreach ($movements as $txn) {
            $qty = (float) $txn->quantity;

            $row = [
                'id' => $txn->id,
                'date' => $txn->created_at,
                'reference' => $this->describe($txn),
                'performed_by' => $txn->performedBy?->name,
                'in_qty' => null,
                'in_price' => null,
                'in_value' => null,
                'out_qty' => null,
                'out_price' => null,
                'out_value' => null,
            ];

            if ($txn->type === TransactionType::In) {
                $price = (float) $txn->unit_cost;
                $inValue = $qty * $price;

                $balanceQty += $qty;
                $balanceValue += $inValue;

                $row['in_qty'] = $qty;
                $row['in_price'] = $price;
                $row['in_value'] = $inValue;
            } else {
                // Issued at the current moving average. If the card opens with
                // an issue (no prior balance) fall back to the row's own cost
                // so the column is never blank.
                $avg = $balanceQty > self::EPSILON
                    ? $balanceValue / $balanceQty
                    : (float) $txn->unit_cost;
                $outValue = $qty * $avg;

                $balanceQty -= $qty;
                $balanceValue -= $outValue;

                if ($balanceQty <= self::EPSILON) {
                    $balanceQty = 0.0;
                    $balanceValue = 0.0;
                }

                $row['out_qty'] = $qty;
                $row['out_price'] = $avg;
                $row['out_value'] = $outValue;
            }

            $row['balance_qty'] = $balanceQty;
            $row['balance_price'] = $balanceQty > self::EPSILON ? $balanceValue / $balanceQty : 0.0;
            $row['balance_value'] = $balanceValue;

            $rows[] = $row;
        }

        return [
            'warehouse' => $warehouse,
            'rows' => $rows,
            'totals' => [
                'quantity' => $balanceQty,
                'unit_cost' => $balanceQty > self::EPSILON ? $balanceValue / $balanceQty : 0.0,
                'value' => $balanceValue,
            ],
        ];
    }

    /**
     * The "بيان" column — a human label for the movement, mirroring the manual
     * card's "اذن اضافة 2026-1 / اذن صرف 2026-1". Resolves the source document
     * (إذن إضافة/صرف/ارتداد/تسليم, أمر شراء/تشغيل) to "<label> <number>", then
     * falls back to the free-text note, then the transaction type label.
     */
    private function describe(InventoryTransaction $txn): string
    {
        if ($txn->reference instanceof Model) {
            $label = $this->documentLabel($txn->reference);

            if ($label !== null) {
                return $label;
            }
        }

        $notes = trim((string) $txn->notes);

        return $notes !== '' ? $notes : $txn->type->getLabel();
    }

    /**
     * "<localized document name> <number>" for a referenced source document,
     * or null when the reference type carries no recognised number.
     */
    private function documentLabel(Model $reference): ?string
    {
        $number = $reference->voucher_number
            ?? $reference->po_number
            ?? $reference->wo_number
            ?? null;

        $key = 'resources.stock_card.documents.' . class_basename($reference);
        $name = __($key);

        // __() echoes the key back when the translation is missing.
        if ($name === $key) {
            return $number !== null ? (string) $number : null;
        }

        return $number !== null ? trim($name . ' ' . $number) : $name;
    }
}
