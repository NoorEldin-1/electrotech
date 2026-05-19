<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BomItem extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'bom_id',
        'item_id',
        'quantity',
        'waste_percentage',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'waste_percentage' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bom_id', 'item_id', 'quantity', 'waste_percentage'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Total quantity including waste allowance.
     */
    public function getTotalRequiredQuantityAttribute(): float
    {
        $wasteMultiplier = 1 + ((float) $this->waste_percentage / 100);

        return (float) $this->quantity * $wasteMultiplier;
    }
}
