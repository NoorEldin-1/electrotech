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
        'operation_name',
        'entry_date',
        'planned_quantity',
        'produced_quantity',
        'scrap_quantity',
        'planned_material_cost',
        'actual_material_cost',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'planned_quantity' => 'decimal:4',
            'produced_quantity' => 'decimal:4',
            'scrap_quantity' => 'decimal:4',
            'planned_material_cost' => 'decimal:2',
            'actual_material_cost' => 'decimal:2',
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

    /**
     * Material loss value (الفاقد) — the difference between the value actually
     * issued (أمر الصرف) and the value planned (طلب التصنيع). Positive = more
     * material was consumed than planned (سلايد 9).
     */
    public function getLossValueAttribute(): float
    {
        return (float) $this->actual_material_cost - (float) $this->planned_material_cost;
    }

    /**
     * Material loss as a percentage of the planned value (نسبة الفاقد قيمياً).
     */
    public function getLossValuePercentageAttribute(): float
    {
        if ((float) $this->planned_material_cost === 0.0) {
            return 0;
        }

        return ($this->loss_value / (float) $this->planned_material_cost) * 100;
    }
}
