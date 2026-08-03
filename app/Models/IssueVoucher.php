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

class IssueVoucher extends Model
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
        // صرف كمية زائدة عن حاجة أمر التصنيع — recorded on the document itself.
        'has_excess',
        'excess_reason',
        'excess_approved_by',
        'excess_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'total_value' => 'decimal:2',
            'voucher_date' => 'date',
            'signed_at' => 'datetime',
            'has_excess' => 'boolean',
            'excess_approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['voucher_number', 'work_order_id', 'status', 'total_value', 'signed_at', 'has_excess', 'excess_reason', 'excess_approved_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Issue voucher {$this->voucher_number} was {$eventName}");
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IssueVoucherLine::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function excessApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excess_approved_by');
    }

    public function isPosted(): bool
    {
        return $this->status === VoucherStatus::Posted;
    }

    /**
     * Whether this voucher was posted knowingly carrying more than the work
     * order's material plan required.
     */
    public function hasExcess(): bool
    {
        return (bool) $this->has_excess;
    }

    public static function generateVoucherNumber(): string
    {
        $prefix = 'ISV-' . now()->format('Ym') . '-';

        return Cache::lock('issue_voucher_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
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
