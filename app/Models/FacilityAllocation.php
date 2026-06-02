<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تخصيص تسهيل لعملية — part of a facility's limit reserved for an operation.
 */
class FacilityAllocation extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'credit_facility_id',
        'project_id',
        'allocated_amount',
        'allocated_at',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
            'allocated_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['credit_facility_id', 'project_id', 'allocated_amount', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class, 'credit_facility_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
