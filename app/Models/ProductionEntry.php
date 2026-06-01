<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record written when a work order is completed: how much was planned,
 * how much was actually produced into finished goods, and the extracted loss.
 */
class ProductionEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'output_item_id',
        'entry_date',
        'planned_quantity',
        'produced_quantity',
        'scrap_quantity',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'planned_quantity' => 'decimal:4',
            'produced_quantity' => 'decimal:4',
            'scrap_quantity' => 'decimal:4',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Loss as a percentage of planned output (الفاقد %).
     */
    public function getScrapPercentageAttribute(): float
    {
        if ((float) $this->planned_quantity === 0.0) {
            return 0;
        }

        return ((float) $this->scrap_quantity / (float) $this->planned_quantity) * 100;
    }
}
