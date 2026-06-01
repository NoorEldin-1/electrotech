<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Sync\Concerns\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InventoryTransaction extends Model
{
    use HasFactory;
    use LogsActivity;
    use Syncable;

    /**
     * Inventory transactions are append-only by design (one row per
     * physical movement, never edited), which makes them the cleanest
     * record type to receive from offline clients — there is literally
     * no conflict scenario possible at the record level.
     *
     * What the OperationProcessor still has to validate:
     *   - the referenced item exists
     *   - the post-write inventory level wouldn't go negative
     *     (re-checked under the InventoryService lock, not here)
     *   - the operator has permission to log this transaction type
     */
    public function syncWritableFields(): array
    {
        return [
            'item_id',
            'type',
            'warehouse_type',
            'quantity',
            'unit_cost',
            'reference_type',
            'reference_id',
            'notes',
            'performed_by',
            'client_updated_at',
        ];
    }

    protected $fillable = [
        'item_id',
        'type',
        'warehouse_type',
        'quantity',
        'unit_cost',
        'reference_type',
        'reference_id',
        'notes',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'warehouse_type' => WarehouseType::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['item_id', 'type', 'quantity', 'reference_type', 'reference_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Stock movement was {$eventName}");
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Polymorphic reference to the source document (PO, WO, etc.)
     */
    public function reference(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('reference');
    }
}
