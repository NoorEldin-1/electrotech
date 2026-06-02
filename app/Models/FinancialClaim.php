<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * المطالبة المالية — a financial claim against the customer for an operation
 * (سلايد 2). Auto-numbered FC-YYYYMM-XXXX; lifecycle draft → submitted →
 * collected (or cancelled).
 */
class FinancialClaim extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'claim_number',
        'project_id',
        'customer_id',
        'claim_date',
        'amount',
        'status',
        'description',
        'submitted_at',
        'collected_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date',
            'amount' => 'decimal:2',
            'status' => ClaimStatus::class,
            'submitted_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FinancialClaim $claim): void {
            if (empty($claim->claim_number)) {
                $claim->claim_number = static::generateClaimNumber();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['claim_number', 'project_id', 'amount', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isDraft(): bool
    {
        return $this->status === ClaimStatus::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status === ClaimStatus::Submitted;
    }

    public function isCollected(): bool
    {
        return $this->status === ClaimStatus::Collected;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate a unique claim number FC-YYYYMM-XXXX (portable ordering — see
     * DeliveryMinute::generateMinuteNumber).
     */
    public static function generateClaimNumber(): string
    {
        $prefix = 'FC-' . now()->format('Ym') . '-';

        return Cache::lock('fc_number_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $last = static::withTrashed()
                ->where('claim_number', 'like', $prefix . '%')
                ->orderByDesc('claim_number')
                ->value('claim_number');

            $sequence = $last ? (int) substr($last, strlen($prefix)) : 0;

            return $prefix . str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
