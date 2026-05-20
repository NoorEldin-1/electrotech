<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BomStatus;
use App\Sync\Concerns\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Bom extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;
    use Syncable;

    /**
     * BOM authoring is an office workflow. Read-only on the floor.
     */
    public function syncWritableFields(): array
    {
        return [];
    }

    protected $fillable = [
        'project_id',
        'version',
        'status',
        'notes',
        'prepared_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BomStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['version', 'status', 'approved_by', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "BOM v{$this->version} was {$eventName}");
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }

    /**
     * Calculate the total estimated cost of this BOM.
     *
     * Loads `items.item` once if it isn't already loaded so iterating the
     * collection cannot trigger an N+1 cascade — the per-item `$item->item`
     * access would otherwise issue one query per BOM line.
     */
    public function getTotalCostAttribute(): float
    {
        $this->loadMissing('items.item:id,unit_cost');

        return (float) $this->items->sum(
            fn (BomItem $item) => (float) $item->quantity * (float) ($item->item->unit_cost ?? 0)
        );
    }
}
