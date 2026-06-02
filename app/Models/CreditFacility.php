<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FacilityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تسهيل ائتماني — a credit line with a limit, monitored across operations via
 * allocations (سلايد 1).
 */
class CreditFacility extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'account_id',
        'customer_id',
        'limit_amount',
        'currency',
        'start_date',
        'end_date',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'limit_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => FacilityStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'limit_amount', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FacilityAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sum of currently-active allocations. */
    public function getUsedAmountAttribute(): float
    {
        return (float) $this->allocations()->where('status', 'active')->sum('allocated_amount');
    }

    /** Remaining headroom on the facility. */
    public function getAvailableAmountAttribute(): float
    {
        return (float) $this->limit_amount - $this->used_amount;
    }
}
