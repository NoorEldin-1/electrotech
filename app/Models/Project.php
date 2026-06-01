<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArrivalMethod;
use App\Enums\AttachmentCategory;
use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use App\Sync\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;
    use Syncable;

    /**
     * Projects are read-only on the factory floor; operators must not
     * mutate budgets, dates, or status from offline. Empty array =
     * sync push for this model is rejected entirely.
     */
    public function syncWritableFields(): array
    {
        return [];
    }

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
                'acceptance_email_at',
                'manager_approved_at',
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
     * Generate a unique project code with format: PRJ-YYYYMM-XXXX
     *
     * Pulls just the max sequence for the current month with a single
     * aggregate query (no model hydration, no full row fetch). Wrapped in
     * a Redis lock to make the generate→insert sequence race-safe under
     * concurrent creates.
     *
     * Soft-deleted rows are intentionally included via withTrashed() — the
     * unique constraint on `code` is enforced at the DB level regardless of
     * deleted_at, so reusing a soft-deleted record's number would hit a
     * "code has already been taken" error on save.
     */
    public static function generateCode(): string
    {
        $prefix = 'PRJ-' . now()->format('Ym') . '-';

        return \Illuminate\Support\Facades\Cache::lock('project_code_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $maxSequence = (int) static::query()
                ->withTrashed()
                ->where('code', 'like', $prefix . '%')
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING_INDEX(code, "-", -1) AS UNSIGNED)), 0) AS seq')
                ->value('seq');

            return $prefix . str_pad((string) ($maxSequence + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
