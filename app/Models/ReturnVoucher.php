<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VoucherStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * إذن ارتداد — returns unconsumed scrap (فضلات) from work-in-progress back to
 * the raw warehouse under a different code. The inverse of IssueVoucher; see
 * App\Services\ReturnVoucherService for the posting logic.
 */
class ReturnVoucher extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'voucher_number',
        'work_order_id',
        'voucher_date',
        'status',
        'total_value',
        'notes',
        'issued_by',
        'signed_by',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'total_value' => 'decimal:2',
            'voucher_date' => 'date',
            'signed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['voucher_number', 'work_order_id', 'status', 'total_value', 'signed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Return voucher {$this->voucher_number} was {$eventName}");
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnVoucherLine::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function isPosted(): bool
    {
        return $this->status === VoucherStatus::Posted;
    }

    public static function generateVoucherNumber(): string
    {
        $prefix = 'RTV-' . now()->format('Ym') . '-';

        return Cache::lock('return_voucher_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $latest = static::query()
                ->withTrashed()
                ->where('voucher_number', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->value('voucher_number');

            $seq = $latest ? (int) substr($latest, strlen($prefix)) : 0;

            return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
