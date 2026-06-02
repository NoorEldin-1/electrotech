<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * محضر تسليم — a delivery minute recorded on delivery and distributed to all
 * departments (سلايد 2). Auto-numbered DM-YYYYMM-XXXX.
 */
class DeliveryMinute extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'minute_number',
        'project_id',
        'delivery_voucher_id',
        'customer_id',
        'minute_date',
        'content',
        'created_by',
        'distributed_at',
    ];

    protected function casts(): array
    {
        return [
            'minute_date' => 'date',
            'distributed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DeliveryMinute $minute): void {
            if (empty($minute->minute_number)) {
                $minute->minute_number = static::generateMinuteNumber();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['minute_number', 'project_id', 'distributed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isDistributed(): bool
    {
        return $this->distributed_at !== null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveryVoucher(): BelongsTo
    {
        return $this->belongsTo(DeliveryVoucher::class);
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
     * Generate a unique minute number with format DM-YYYYMM-XXXX.
     *
     * The 4-digit zero-padded sequence means lexical order equals numeric
     * order within a month, so a portable ORDER BY (no SUBSTRING_INDEX) works
     * on both MySQL and the SQLite test database. See WorkOrder::generateWoNumber
     * for the Redis-lock rationale.
     */
    public static function generateMinuteNumber(): string
    {
        $prefix = 'DM-' . now()->format('Ym') . '-';

        return Cache::lock('dm_number_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $last = static::withTrashed()
                ->where('minute_number', 'like', $prefix . '%')
                ->orderByDesc('minute_number')
                ->value('minute_number');

            $sequence = $last ? (int) substr($last, strlen($prefix)) : 0;

            return $prefix . str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
