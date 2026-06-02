<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Enums\WarehouseType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A hold placed on stock for an operation (حجز الكمية للعملية). Reduces the
 * item's available quantity in a warehouse without changing on-hand, until it
 * is released (typically when the materials are issued).
 */
class StockReservation extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'item_id',
        'warehouse_type',
        'quantity',
        'status',
        'notes',
        'created_by',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_type' => WarehouseType::class,
            'status' => ReservationStatus::class,
            'quantity' => 'decimal:4',
            'released_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'item_id', 'warehouse_type', 'quantity', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isActive(): bool
    {
        return $this->status === ReservationStatus::Active;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
