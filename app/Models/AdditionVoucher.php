<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseInvoicingStatus;
use App\Enums\VoucherStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'supplier_name',
        'purchase_order_id',
        'invoice_number',
        'invoice_date',
        'invoice_value',
        'received_value',
        'invoicing_status',
        'closure_reason',
        'closed_at',
        'closed_by',
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
            'invoicing_status' => PurchaseInvoicingStatus::class,
            'invoice_value' => 'decimal:2',
            'received_value' => 'decimal:2',
            'invoice_date' => 'date',
            'voucher_date' => 'date',
            'closed_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Slide 11: the invoicing status is DERIVED, never typed in. Deriving
        // it on every save means it cannot lie, whichever path wrote the
        // voucher (form, service, seeder, factory).
        static::saving(function (AdditionVoucher $voucher): void {
            $voucher->invoicing_status = match (true) {
                filled($voucher->invoice_number) => PurchaseInvoicingStatus::Invoiced,
                $voucher->closed_at !== null => PurchaseInvoicingStatus::ClosedUninvoiced,
                default => PurchaseInvoicingStatus::NotInvoiced,
            };
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'voucher_number', 'supplier_id', 'invoice_number', 'invoice_value', 'status', 'posted_at',
                // Slide 11: who invoiced or closed the voucher, and why, is an
                // audit question — keep it in the activity log.
                'invoice_date', 'invoicing_status', 'closure_reason', 'closed_at', 'closed_by',
            ])
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

    /**
     * Scanned إذن إضافة document (slide 7). Polymorphic — see
     * Attachment::attachable().
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Slide 9: a registered supplier is optional; fall back to the free-text
     * name when the voucher has no linked supplier (e.g. receipts without an
     * invoice or purchase order).
     */
    public function getSupplierLabelAttribute(): ?string
    {
        return $this->supplier?->name ?: $this->supplier_name;
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isPosted(): bool
    {
        return $this->status === VoucherStatus::Posted;
    }

    public function isInvoiced(): bool
    {
        return $this->invoicing_status === PurchaseInvoicingStatus::Invoiced;
    }

    public function isClosedUninvoiced(): bool
    {
        return $this->invoicing_status === PurchaseInvoicingStatus::ClosedUninvoiced;
    }

    /**
     * Slide 11's reconciliation rule per voucher: the supplier invoice value
     * must equal the value that actually entered the store. Returns the signed
     * difference, or null when there is nothing to compare yet.
     */
    public function invoiceValueMismatch(): ?float
    {
        if (! $this->isInvoiced() || (float) $this->invoice_value <= 0.0) {
            return null;
        }

        $difference = round((float) $this->invoice_value - (float) $this->received_value, 2);

        return abs($difference) > 0.01 ? $difference : null;
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
