<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
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

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
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
     * Get the current available quantity (on_hand - on_hold).
     */
    public function getAvailableQuantityAttribute(): float
    {
        $inventory = $this->inventory;

        if (! $inventory) {
            return 0;
        }

        return (float) $inventory->on_hand_quantity - (float) $inventory->on_hold_quantity;
    }

    /**
     * Check if current stock is below minimum threshold.
     */
    public function isBelowMinimumStock(): bool
    {
        return $this->available_quantity < (float) $this->minimum_stock;
    }
}
