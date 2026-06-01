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

class AdditionVoucher extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'voucher_number',
        'supplier_id',
        'purchase_order_id',
        'invoice_number',
        'invoice_value',
        'voucher_date',
        'status',
        'notes',
        'received_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'invoice_value' => 'decimal:2',
            'voucher_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['voucher_number', 'supplier_id', 'invoice_number', 'invoice_value', 'status', 'posted_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Addition voucher {$this->voucher_number} was {$eventName}");
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AdditionVoucherLine::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->status === VoucherStatus::Posted;
    }

    /**
     * Stock value of the lines (Σ qty × unit_cost). Used as the supplier
     * posting amount when no explicit invoice_value was entered.
     */
    public function getLinesValueAttribute(): float
    {
        return (float) $this->lines->sum(
            fn (AdditionVoucherLine $line) => (float) $line->quantity * (float) $line->unit_cost
        );
    }

    /**
     * Generate a unique voucher number: AV-YYYYMM-XXXX.
     * Redis lock makes the read→increment race-safe; the numeric suffix is
     * parsed in PHP so the query stays portable across MySQL and SQLite.
     */
    public static function generateVoucherNumber(): string
    {
        $prefix = 'AV-' . now()->format('Ym') . '-';

        return Cache::lock('addition_voucher_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
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
