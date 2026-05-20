<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Inventory extends Model
{
    use HasFactory;
    use LogsActivity;
    use Syncable;

    /**
     * Inventory levels are NEVER edited directly by sync clients; they
     * change as a side effect of accepted InventoryTransaction pushes.
     * Empty array enforces this on the API surface; the
     * InventoryService remains the only legitimate mutator.
     */
    public function syncWritableFields(): array
    {
        return [];
    }

    protected $table = 'inventories';

    protected $fillable = [
        'item_id',
        'warehouse_type',
        'on_hand_quantity',
        'on_hold_quantity',
    ];

    protected function casts(): array
    {
        return [
            'on_hand_quantity' => 'decimal:4',
            'on_hold_quantity' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['on_hand_quantity', 'on_hold_quantity', 'warehouse_type'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id');
    }

    /**
     * Available = on_hand - on_hold. Never negative in valid state.
     */
    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->on_hand_quantity - (float) $this->on_hold_quantity;
    }
}
