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
        'output_item_id',
        'wo_number',
        'title',
        'description',
        // المواصفات الفنية (سلايد 2–3) — يؤلّفها المكتب وتُنسَخ لورقة الجودة.
        'conductor_type',
        'cross_section',
        'cross_section_e',
        'external_body',
        'protection_degree',
        'paint',
        'model',
        'ampere',
        'poles_count',
        'status',
        'priority',
        'planned_quantity',
        'produced_quantity',
        'waste_quantity',
        'estimated_cost',
        'actual_material_cost',
        'planned_start_date',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'manufacturing_finished_at',
        'manufacturing_duration_minutes',
        'manufacturing_finished_by',
        'assigned_to',
        'created_by',
        'qa_approved_by',
        'qa_approved_at',
        'qa_notes',
        // اعتماد مدير مكتب المشروعات (سلايد 5) — the PMO-manager gate.
        'order_approved_by',
        'order_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'poles_count' => 'integer',
            'planned_quantity' => 'decimal:4',
            'produced_quantity' => 'decimal:4',
            'waste_quantity' => 'decimal:4',
            'estimated_cost' => 'decimal:2',
            'actual_material_cost' => 'decimal:2',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'datetime',
            'actual_end_date' => 'datetime',
            'manufacturing_finished_at' => 'datetime',
            'manufacturing_duration_minutes' => 'integer',
            'qa_approved_at' => 'datetime',
            'order_approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Snapshot the estimated cost from the linked BOM at creation time
        // (سلايد 2 المقارنة). Frozen so it stays stable if item prices change.
        static::creating(function (WorkOrder $workOrder): void {
            if ($workOrder->bom_id && (float) ($workOrder->estimated_cost ?? 0) === 0.0) {
                $workOrder->estimated_cost = $workOrder->bom?->total_cost ?? 0;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['wo_number', 'status', 'produced_quantity', 'waste_quantity', 'qa_approved_by', 'order_approved_by', 'manufacturing_finished_at'])
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

    /**
     * Editable per-order material lines (سلايد 6). Seeded from the output
     * item's standard BOM, then adjustable for this order; the issue voucher
     * is built from these rather than the raw BOM.
     */
    public function materials(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    public function productionEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductionEntry::class);
    }

    public function depreciationVouchers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DepreciationVoucher::class);
    }

    public function qualitySheets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QualitySheet::class);
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

    public function orderApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'order_approved_by');
    }

    public function manufacturingFinishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manufacturing_finished_by');
    }

    /**
     * Planned material cost = Σ(quantity × unit_cost) of this order's material
     * lines (المخطط). Basis for the estimate-vs-actual comparison and for the
     * planned side of the production/loss report (سلايد 9).
     */
    public function getPlannedMaterialCostAttribute(): float
    {
        return (float) $this->materials->sum(
            fn (WorkOrderMaterial $line) => (float) $line->quantity * (float) $line->unit_cost
        );
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
     * Cost variance vs the operating-order estimate (actual − estimated).
     * Positive = over budget.
     */
    public function getCostVarianceAttribute(): float
    {
        return (float) $this->actual_material_cost - (float) $this->estimated_cost;
    }

    /**
     * Cost variance as a percentage of the estimate, or null if no estimate.
     */
    public function getCostVariancePercentAttribute(): ?float
    {
        $estimated = (float) $this->estimated_cost;

        if ($estimated === 0.0) {
            return null;
        }

        return ($this->cost_variance / $estimated) * 100;
    }

    /**
     * Check if QA gate has been passed.
     */
    public function isQaApproved(): bool
    {
        return $this->qa_approved_by !== null && $this->qa_approved_at !== null;
    }

    /**
     * Whether the PMO manager has approved this order out of Draft (سلايد 5) —
     * the gate that releases it for manufacturing to start.
     */
    public function isOrderApproved(): bool
    {
        return $this->order_approved_by !== null && $this->order_approved_at !== null;
    }

    /**
     * Whether manufacturing has been marked finished as a whole (التصنيع سلايد
     * 2) — the stage-independent "انتهاء التصنيع" signal, distinct from the
     * QA-gated completion.
     */
    public function isManufacturingFinished(): bool
    {
        return $this->manufacturing_finished_at !== null;
    }

    /**
     * Human-readable manufacturing duration (e.g. "2 days 3 hours"), localized
     * to the app locale so it reads naturally in Arabic/RTL. Null until the WO
     * is marked finished.
     */
    public function getManufacturingDurationHumanAttribute(): ?string
    {
        if ($this->manufacturing_duration_minutes === null) {
            return null;
        }

        return \Carbon\CarbonInterval::minutes($this->manufacturing_duration_minutes)
            ->cascade()
            ->forHumans(['short' => false]);
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
