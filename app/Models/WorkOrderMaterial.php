<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A single material line on a work order (سلايد 6). Seeded from the finished
 * good's standard BOM (scaled by planned quantity) then editable per order —
 * the manual-override layer that keeps the product's standard recipe fixed.
 *
 * Not Syncable — like IssueVoucherLine, these child lines are authored
 * server-side (office) and reach the floor only through their parent order.
 */
class WorkOrderMaterial extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'work_order_id',
        'item_id',
        'quantity',
        'unit_cost',
        'is_manual',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'is_manual' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['work_order_id', 'item_id', 'quantity', 'unit_cost', 'is_manual'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
