<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArrivalMethod;
use App\Enums\AttachmentCategory;
use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'client_name',
        'customer_id',
        'consultant_name',
        'engineer_name',
        'electric_current',
        'model',
        'section_type',
        'poles_count',
        'quantity',
        'project_location',
        'arrival_method',
        'description',
        'status',
        'estimated_budget',
        'actual_cost',
        'start_date',
        'end_date',
        'alarm_at',
        'alarm_note',
        'smb_status',
        'smb_received_at',
        'acceptance_email_at',
        'manager_approved_at',
        'manager_approved_by',
        'lost_reason',
        'lost_reason_note',
        'winning_competitor',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'arrival_method' => ArrivalMethod::class,
            'lost_reason' => LostReason::class,
            'estimated_budget' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'alarm_at' => 'datetime',
            'smb_received_at' => 'date',
            'acceptance_email_at' => 'date',
            'manager_approved_at' => 'datetime',
            'poles_count' => 'integer',
            'quantity' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'code',
                'status',
                'estimated_budget',
                'actual_cost',
                'alarm_at',
                'smb_status',
                'smb_received_at',
                'acceptance_email_at',
                'manager_approved_at',
                'end_date',
                'lost_reason',
                'winning_competitor',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Project '{$this->name}' was {$eventName}");
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function deliveryVouchers(): HasMany
    {
        return $this->hasMany(DeliveryVoucher::class);
    }

    /**
     * GL lines tagged to this operation (cost-center dimension). Read-only —
     * the General Ledger owns writes; the Operation Cost Center reads.
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * Party-ledger postings (supplier/customer) tagged to this operation.
     */
    public function accountEntries(): HasMany
    {
        return $this->hasMany(AccountEntry::class);
    }

    /**
     * إقفالات مركز التكلفة (سلايد 12) — every closing of this operation's cost
     * centre into cost of goods sold, plus the reversals that undid any of them.
     */
    public function costCenterClosings(): HasMany
    {
        return $this->hasMany(CostCenterClosing::class);
    }

    /**
     * Cash payments / receipts recorded against this operation (الدفعات).
     */
    public function operationPayments(): HasMany
    {
        return $this->hasMany(OperationPayment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function attachmentsByCategory(AttachmentCategory $category): HasMany
    {
        return $this->attachments()->where('category', $category->value);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProjectOffer::class);
    }

    /**
     * Latest offer by version. The version column is per-project
     * monotonic, so ordering by it is stable even when two offers
     * are submitted in the same second.
     */
    public function latestOffer(): HasOne
    {
        return $this->hasOne(ProjectOffer::class)->latestOfMany('version');
    }

    /**
     * Whether this operation carries at least one offer with a real price
     * (financial_amount > 0). Drives the "missing offer" Sales alert — Slide 5
     * asks the system to flag an operation added without a financial/technical
     * offer.
     */
    public function hasPricedOffer(): bool
    {
        return $this->offers()->where('financial_amount', '>', 0)->exists();
    }

    public function scopeMissingPricedOffer(Builder $query): Builder
    {
        return $query->whereDoesntHave('offers', fn (Builder $q) => $q->where('financial_amount', '>', 0));
    }

    /**
     * Whether this operation has its SMB document on file. SMB and Submittal
     * are the same artifact (شريحة 11): the spec/sample submittal compiled for
     * the operation. The presence of a Submittal attachment is the single
     * source of truth, surfaced as the In-Hand "SMB" indicator — mirroring the
     * "missing offer" alert.
     */
    public function hasSmb(): bool
    {
        return $this->attachmentsByCategory(AttachmentCategory::Submittal)->exists();
    }

    public function managerApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function scopeTender(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Tender);
    }

    public function scopeInHand(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::InHand);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::InProgress);
    }

    public function scopeLost(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Lost);
    }

    /**
     * Generate a unique project code with format: YYYY-N (e.g. "2026-1").
     *
     * Slide 4 of the Sales modifications: the code is keyed by YEAR only
     * (not month) and the sequence is not zero-padded. Wrapped in a cache
     * lock to make the generate→insert sequence race-safe under concurrent
     * creates.
     *
     * Soft-deleted rows are intentionally included via withTrashed() — the
     * unique constraint on `code` is enforced at the DB level regardless of
     * deleted_at, so reusing a soft-deleted record's number would hit a
     * "code has already been taken" error on save. Legacy "PRJ-YYYYMM-XXXX"
     * codes never match the "YYYY-%" filter, so they don't skew the sequence.
     */
    public static function generateCode(): string
    {
        // Slide 4: the operation code is by YEAR only (e.g. "2026-1"), not by
        // month. Sequence resets each calendar year and is not zero-padded.
        $prefix = now()->format('Y').'-';

        return Cache::lock('project_code_seq:'.$prefix, 5)->block(3, function () use ($prefix) {
            // Computed in PHP (not SUBSTRING_INDEX) so it is portable across
            // MySQL (production) and SQLite (tests). Volume per year is small.
            $maxSequence = static::query()
                ->withTrashed()
                ->where('code', 'like', $prefix.'%')
                ->pluck('code')
                ->map(fn (string $code): int => (int) substr((string) strrchr($code, '-'), 1))
                ->max() ?? 0;

            return $prefix.($maxSequence + 1);
        });
    }

    /**
     * Slide 6: a brand-new operation must land in the Tender list
     * (عمليات المناقصات), not silently in "Draft". The intake form already
     * defaults the (disabled) status field to Tender; this guard covers any
     * other creation path (factory states that null the status, future API)
     * by defaulting an unset status to Tender. Explicit statuses — including
     * a deliberate Draft — are never overridden.
     */
    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            if ($project->status === null) {
                $project->status = ProjectStatus::Tender;
            }
        });
    }
}
