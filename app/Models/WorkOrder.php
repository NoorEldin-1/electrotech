<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkOrderStatus;
use App\Sync\Concerns\Syncable;
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
    use Syncable;

    protected $fillable = [
        'project_id',
        'bom_id',
        'output_item_id',
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

    /**
     * Sync clients (operators on the factory floor) have a narrower
     * write surface than admin form submissions. They can advance state
     * and record the actuals; they cannot rewrite plans or reassign.
     *
     * Anything outside this list submitted via the sync push is silently
     * dropped (with a debug-level log line). Server-side authoring
     * through Filament / services is unaffected.
     */
    public function syncWritableFields(): array
    {
        return [
            'status',
            'produced_quantity',
            'waste_quantity',
            'actual_start_date',
            'actual_end_date',
            'qa_approved_by',
            'qa_approved_at',
            'qa_notes',
            'client_updated_at',
        ];
    }

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

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function issueVouchers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IssueVoucher::class);
    }

    public function productionEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductionEntry::class);
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
     *
     * See App\Models\Project::generateCode() for the rationale on the
     * MAX(CAST(...)) aggregate + Redis lock pattern.
     */
    public static function generateWoNumber(): string
    {
        $prefix = 'WO-' . now()->format('Ym') . '-';

        return \Illuminate\Support\Facades\Cache::lock('wo_number_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $maxSequence = (int) static::query()
                ->where('wo_number', 'like', $prefix . '%')
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING_INDEX(wo_number, "-", -1) AS UNSIGNED)), 0) AS seq')
                ->value('seq');

            return $prefix . str_pad((string) ($maxSequence + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
