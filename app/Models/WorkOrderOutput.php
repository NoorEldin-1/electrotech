<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * منتج تام واحد من منتجات أمر التصنيع، بكميته المخططة. أمر التصنيع ينتج أكثر
 * من منتج، ولكل منتج كميته — والخامات المطلوبة للأمر هي مجموع تركيبات هذه
 * المنتجات مضروبة في كمياتها.
 *
 * الكمية المنتجة والهالك لا تُدخَلان هنا من شاشة الإنشاء/التعديل، بل في مرحلة
 * «إرسال لضمان الجودة» من أزرار الأمر.
 */
class WorkOrderOutput extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'work_order_id',
        'item_id',
        'planned_quantity',
        'produced_quantity',
        'waste_quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:4',
            'produced_quantity' => 'decimal:4',
            'waste_quantity' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['work_order_id', 'item_id', 'planned_quantity', 'produced_quantity', 'waste_quantity'])
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
