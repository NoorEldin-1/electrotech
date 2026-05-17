<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WorkOrder extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'bom_id',
        'wo_number',
        'title',
        'description',
        'status',
        'priority',
        'planned_quantity',
        'produced_quantity',
        'waste_quantity',
        'planned_start_date',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'assigned_to',
        'created_by',
        'qa_approved_by',
        'qa_approved_at',
        'qa_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'planned_quantity' => 'decimal:4',
            'produced_quantity' => 'decimal:4',
            'waste_quantity' => 'decimal:4',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'datetime',
            'actual_end_date' => 'datetime',
            'qa_approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['wo_number', 'status', 'produced_quantity', 'waste_quantity', 'qa_approved_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "WO #{$this->wo_number} was {$eventName}");
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function qaApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qa_approved_by');
    }

    /**
     * Calculate the waste percentage vs planned output.
     */
    public function getWastePercentageAttribute(): float
    {
        if ((float) $this->planned_quantity === 0.0) {
            return 0;
        }

        return ((float) $this->waste_quantity / (float) $this->planned_quantity) * 100;
    }

    /**
     * Calculate production efficiency as percentage.
     */
    public function getEfficiencyAttribute(): float
    {
        if ((float) $this->planned_quantity === 0.0) {
            return 0;
        }

        return ((float) $this->produced_quantity / (float) $this->planned_quantity) * 100;
    }

    /**
     * Check if QA gate has been passed.
     */
    public function isQaApproved(): bool
    {
        return $this->qa_approved_by !== null && $this->qa_approved_at !== null;
    }

    /**
     * Generate a unique WO number with format: WO-YYYYMM-XXXX
     */
    public static function generateWoNumber(): string
    {
        $prefix = 'WO-' . now()->format('Ym') . '-';
        $lastWo = static::where('wo_number', 'like', $prefix . '%')
            ->orderByDesc('wo_number')
            ->first();

        $sequence = $lastWo
            ? ((int) substr($lastWo->wo_number, -4)) + 1
            : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
