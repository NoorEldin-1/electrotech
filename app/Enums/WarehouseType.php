<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The three logical warehouses the Inventory Department moves goods through:
 *   raw_materials  → مخزن الخامات      (received from purchases via Addition Voucher)
 *   work_in_progress → مخزن تحت التشغيل (issued to a Work Order via Issue Voucher)
 *   finished_goods → مخزن المنتجات التامة (produced on WO completion, leaves via Delivery Voucher)
 *
 * Stock balances are tracked per (item, warehouse) in the `inventories`
 * table, so the same item can hold quantity in more than one warehouse and
 * move between them through a transfer.
 */
enum WarehouseType: string implements HasLabel, HasColor, HasIcon
{
    case RawMaterials = 'raw_materials';
    case WorkInProgress = 'work_in_progress';
    case FinishedGoods = 'finished_goods';

    public function getLabel(): string
    {
        return __('resources.enums.warehouse_type.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RawMaterials => 'info',
            self::WorkInProgress => 'warning',
            self::FinishedGoods => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::RawMaterials => 'heroicon-o-cube',
            self::WorkInProgress => 'heroicon-o-cog-6-tooth',
            self::FinishedGoods => 'heroicon-o-check-badge',
        };
    }

    /**
     * The default ("home") warehouse for an item of the given type. This is
     * where stock first lands and what the catalog screens display.
     */
    public static function homeFor(ItemType $type): self
    {
        return match ($type) {
            ItemType::FinishedGood => self::FinishedGoods,
            ItemType::SemiFinished => self::WorkInProgress,
            default => self::RawMaterials,
        };
    }
}
