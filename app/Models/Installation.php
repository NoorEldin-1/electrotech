<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * مرحلة تركيب لعملية (سلايد 2). Expenses are booked to GL (account 5020) tagged
 * to the operation; this record only tracks the installation stage.
 */
class Installation extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'delivery_voucher_id',
        'status',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallationStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'status', 'started_at', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isPending(): bool
    {
        return $this->status === InstallationStatus::Pending;
    }

    public function isInProgress(): bool
    {
        return $this->status === InstallationStatus::InProgress;
    }

    public function isCompleted(): bool
    {
        return $this->status === InstallationStatus::Completed;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveryVoucher(): BelongsTo
    {
        return $this->belongsTo(DeliveryVoucher::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
