<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Enums\WarehouseType;
use App\Sync\Concerns\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Item extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;
    use Syncable;

    /**
     * Catalog data; operators view but never edit.
     */
    public function syncWritableFields(): array
    {
        return [];
    }

    protected $fillable = [
        'name',
        'sku',
        'type',
        'unit',
        'unit_cost',
        'description',
        'minimum_stock',
    ];

    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'unit' => UnitOfMeasure::class,
            'unit_cost' => 'decimal:2',
            'minimum_stock' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'type', 'unit_cost', 'minimum_stock'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The item's stock row in its HOME warehouse. The home row is always the
     * first created for an item (stock lands there before it can be moved
     * elsewhere), so `oldestOfMany` resolves it deterministically and stays
     * eager-loadable for the catalog screens.
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class)->oldestOfMany();
    }

    /**
     * All per-warehouse balances for this item.
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * The default warehouse where this item's stock lives, derived from its type.
     */
    public function homeWarehouse(): WarehouseType
    {
        return WarehouseType::homeFor($this->type);
    }

    /**
     * On-hand quantity in a specific warehouse (0 if no row yet).
     */
    public function quantityIn(WarehouseType $warehouse): float
    {
        return (float) ($this->inventories
            ->firstWhere('warehouse_type', $warehouse->value)?->on_hand_quantity ?? 0);
    }

    /**
     * Available (on_hand - on_hold) quantity in a specific warehouse.
     */
    public function availableIn(WarehouseType $warehouse): float
    {
        $row = $this->inventories->firstWhere('warehouse_type', $warehouse->value);

        if (! $row) {
            return 0;
        }

        return (float) $row->on_hand_quantity - (float) $row->on_hold_quantity;
    }

    /**
     * Total available quantity across every warehouse (on_hand - on_hold).
     */
    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->inventories->sum(
            fn (Inventory $row) => (float) $row->on_hand_quantity - (float) $row->on_hold_quantity
        );
    }

    /**
     * Check if total available stock is below minimum threshold.
     */
    public function isBelowMinimumStock(): bool
    {
        return $this->available_quantity < (float) $this->minimum_stock;
    }
}
