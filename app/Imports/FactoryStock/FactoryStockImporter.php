<?php

declare(strict_types=1);

namespace App\Imports\FactoryStock;

use App\Enums\ReservationStatus;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockReservation;
use App\Services\InventoryService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Loads the factory-warehouse sheet into the item catalog.
 *
 *   1. (optional) retire the legacy/demo items the sheet replaces;
 *   2. upsert one item per sheet row, keyed by SKU, with its category,
 *      specifications and photo;
 *   3. book the sheet's count as the opening balance (رصيد افتتاحي) in the
 *      item's home warehouse — only for items with no stock movements yet,
 *      so running the import again never doubles the stock.
 *
 * A dry run computes exactly the same plan without writing anything.
 */
final class FactoryStockImporter
{
    public const OPENING_NOTE = 'رصيد افتتاحي — استيراد شيت مخزن المصنع';

    /**
     * Every column that points at an item, other than its own stock rows.
     * An item referenced from any of them appears in a document and can only
     * be retired (soft-deleted), never removed outright.
     */
    private const REFERENCES = [
        ['inventory_transactions', 'item_id'],
        ['purchase_order_items', 'item_id'],
        ['addition_voucher_lines', 'item_id'],
        ['issue_voucher_lines', 'item_id'],
        ['return_voucher_lines', 'item_id'],
        ['delivery_voucher_lines', 'item_id'],
        ['depreciation_voucher_lines', 'item_id'],
        ['bom_items', 'item_id'],
        ['boms', 'output_item_id'],
        ['work_orders', 'output_item_id'],
        ['work_order_outputs', 'item_id'],
        ['work_order_materials', 'item_id'],
        ['production_entries', 'output_item_id'],
        ['stock_reservations', 'item_id'],
        ['items', 'scrap_source_item_id'],
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReservationService $reservations,
        private readonly ImageStore $images,
    ) {}

    public function run(string $path, bool $dryRun = false, bool $purgeLegacy = false): ImportResult
    {
        $reader = new WorkbookReader($path);
        ['items' => $rows, 'skipped' => $skipped] = (new RowNormalizer())->normalize($reader->rows());

        $result = new ImportResult($dryRun, $skipped);
        $sheetSkus = array_map(fn (NormalizedItem $row): string => mb_strtolower($row->sku), $rows);

        // Photos are written before the database transaction: they are
        // content-addressed, so a rolled-back run leaves only reusable files.
        $imagePaths = [];
        foreach ($rows as $row) {
            if ($row->imageEntry === null) {
                continue;
            }

            try {
                $bytes = $reader->imageBytes($row->imageEntry);
                $imagePaths[$row->row] = $dryRun ? $this->images->pathFor($bytes) : $this->images->store($bytes);
            } catch (Throwable) {
                $row->warnings[] = 'تعذّر قراءة الصورة — استُورد الصنف بدون صورة';
            }
        }

        $apply = function () use ($rows, $sheetSkus, $imagePaths, $purgeLegacy, $dryRun, $result): void {
            if ($purgeLegacy) {
                $this->retireLegacyItems($sheetSkus, $dryRun, $result);
            }

            $categories = $this->categories($rows, $dryRun);

            foreach ($rows as $row) {
                $this->importRow($row, $categories[$row->category] ?? null, $imagePaths[$row->row] ?? null, $dryRun, $result);
            }
        };

        $dryRun ? $apply() : DB::transaction($apply);

        return $result;
    }

    /**
     * Items that are not in the sheet are legacy/demo data. Unreferenced ones
     * are deleted outright; ones used by documents are retired: their active
     * reservations released, their stock rows removed and the item
     * soft-deleted, so old documents still open but the item disappears from
     * every list, picker and stock figure. A retired item whose code the
     * sheet reuses gets its code renamed so the real item can take it.
     *
     * @param  list<string>  $sheetSkus  lower-cased
     */
    private function retireLegacyItems(array $sheetSkus, bool $dryRun, ImportResult $result): void
    {
        $legacy = Item::query()
            ->whereNotIn(DB::raw('LOWER(sku)'), $sheetSkus)
            ->with('inventories')
            ->orderBy('id')
            ->get();

        foreach ($legacy as $item) {
            $onHand = (float) $item->inventories->sum('on_hand_quantity');
            $referenced = $this->isReferenced($item);

            $result->purged[] = [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'on_hand' => $onHand,
                'action' => $referenced ? 'retired' : 'deleted',
            ];

            if ($dryRun) {
                continue;
            }

            if (! $referenced) {
                $item->inventories()->delete();
                $item->forceDelete();

                continue;
            }

            StockReservation::query()
                ->where('item_id', $item->id)
                ->where('status', ReservationStatus::Active->value)
                ->get()
                ->each(fn (StockReservation $reservation) => $this->reservations->release($reservation));

            $item->inventories()->delete();
            $item->delete();
        }

        // Free any code the sheet reuses, including on items retired earlier.
        $clashing = Item::onlyTrashed()->whereIn(DB::raw('LOWER(sku)'), $sheetSkus)->get();
        foreach ($clashing as $item) {
            if (! $dryRun) {
                $item->forceFill(['sku' => "{$item->sku}-OLD-{$item->id}"])->saveQuietly();
            }
        }
    }

    private function isReferenced(Item $item): bool
    {
        foreach (self::REFERENCES as [$table, $column]) {
            if (Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $item->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Category name => id (null ids in a dry run for categories not yet
     * created). Sort order follows the sheet.
     *
     * @param  list<NormalizedItem>  $rows
     * @return array<string, ?int>
     */
    private function categories(array $rows, bool $dryRun): array
    {
        $ids = [];
        $sort = 0;

        foreach ($rows as $row) {
            if (array_key_exists($row->category, $ids)) {
                continue;
            }

            $sort++;
            $existing = ItemCategory::firstWhere('name', $row->category);

            $ids[$row->category] = match (true) {
                $existing !== null => $existing->id,
                $dryRun => null,
                default => ItemCategory::create(['name' => $row->category, 'sort' => $sort])->id,
            };
        }

        return $ids;
    }

    private function importRow(NormalizedItem $row, ?int $categoryId, ?string $imagePath, bool $dryRun, ImportResult $result): void
    {
        $item = Item::withTrashed()->where('sku', $row->sku)->first();
        $isNew = $item === null;

        $hasMovements = ! $isNew && $item->inventoryTransactions()->exists();
        $stockAction = match (true) {
            $row->quantity <= 0 => 'none',
            $hasMovements => 'kept',
            default => 'opening',
        };

        if ($stockAction === 'kept') {
            $row->warnings[] = 'للصنف حركات مخزون سابقة — لم يُضف رصيد افتتاحي';
        }

        $result->rows[] = [
            'item' => $row,
            'action' => $isNew ? 'created' : 'updated',
            'stock' => $stockAction,
            'image' => $imagePath !== null,
        ];

        if ($dryRun) {
            return;
        }

        $item ??= new Item(['sku' => $row->sku, 'unit_cost' => 0, 'minimum_stock' => 0]);
        $item->fill($row->attributes());
        $item->item_category_id = $categoryId;
        if ($imagePath !== null) {
            $item->image_path = $imagePath;
        }
        $item->save();

        if ($item->trashed()) {
            $item->restore();
        }

        if ($stockAction === 'opening') {
            $this->inventory->addStock(
                item: $item,
                quantity: $row->quantity,
                notes: self::OPENING_NOTE,
                warehouse: $item->homeWarehouse(),
                unitCost: (float) $item->unit_cost,
            );
        }
    }
}
