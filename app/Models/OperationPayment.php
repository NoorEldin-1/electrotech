<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * دفعة نقدية / مقبوض على عملية (سلايد 1). Auto-numbered PMT-YYYYMM-XXXX.
 */
class OperationPayment extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'payment_number',
        'project_id',
        'customer_id',
        'financial_claim_id',
        'direction',
        'method',
        'account_id',
        'counter_account_id',
        'amount',
        'currency',
        'payment_date',
        'reference',
        'journal_entry_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OperationPayment $payment): void {
            if (empty($payment->payment_number)) {
                $payment->payment_number = static::generatePaymentNumber();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['payment_number', 'project_id', 'direction', 'amount', 'method', 'payment_date', 'journal_entry_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isIncoming(): bool
    {
        return $this->direction === PaymentDirection::Incoming;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function financialClaim(): BelongsTo
    {
        return $this->belongsTo(FinancialClaim::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Portable sequential number PMT-YYYYMM-XXXX (see DeliveryMinute).
     */
    public static function generatePaymentNumber(): string
    {
        $prefix = 'PMT-' . now()->format('Ym') . '-';

        return Cache::lock('pmt_number_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $last = static::withTrashed()
                ->where('payment_number', 'like', $prefix . '%')
                ->orderByDesc('payment_number')
                ->value('payment_number');

            $sequence = $last ? (int) substr($last, strlen($prefix)) : 0;

            return $prefix . str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
