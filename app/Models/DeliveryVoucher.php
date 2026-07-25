<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\InvoicingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DeliveryVoucher extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'voucher_number',
        'customer_id',
        'project_id',
        'supply_order_number',
        'voucher_date',
        'plates_count',
        'protection_degree',
        'insulation_voltage',
        'status',
        'total_value',
        'invoiced_value',
        'invoicing_status',
        'non_invoice_reason',
        'technical_approved_by',
        'technical_approved_at',
        'financial_approved_by',
        'financial_approved_at',
        'notes',
        'created_by',
        'activated_at',
    ];

    /**
     * Mirror the table defaults so a voucher reports its invoicing state even
     * before it is reloaded from the database.
     */
    protected $attributes = [
        'invoiced_value' => 0,
        'invoicing_status' => InvoicingStatus::NotInvoiced->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryVoucherStatus::class,
            'voucher_date' => 'date',
            'plates_count' => 'integer',
            'total_value' => 'decimal:2',
            'invoiced_value' => 'decimal:2',
            'invoicing_status' => InvoicingStatus::class,
            'technical_approved_at' => 'datetime',
            'financial_approved_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['voucher_number', 'customer_id', 'status', 'total_value', 'technical_approved_by', 'financial_approved_by', 'activated_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Delivery voucher {$this->voucher_number} was {$eventName}");
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryVoucherLine::class);
    }

    /**
     * فواتير المبيعات الصادرة على هذا الإذن (سلايد 10) — a voucher may be
     * invoiced in parts, so it carries many invoices.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }

    public function technicalApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technical_approved_by');
    }

    public function financialApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'financial_approved_by');
    }

    public function isActive(): bool
    {
        return $this->status === DeliveryVoucherStatus::Active;
    }

    public function isTechnicalApproved(): bool
    {
        return $this->technical_approved_by !== null;
    }

    public function isFinancialApproved(): bool
    {
        return $this->financial_approved_by !== null;
    }

    public function isFullyApproved(): bool
    {
        return $this->isTechnicalApproved() && $this->isFinancialApproved();
    }

    public function isFullyInvoiced(): bool
    {
        return $this->invoicing_status === InvoicingStatus::FullyInvoiced;
    }

    public function getLinesValueAttribute(): float
    {
        return (float) $this->lines->sum(
            fn (DeliveryVoucherLine $line) => (float) $line->quantity * (float) $line->unit_cost
        );
    }

    public static function generateVoucherNumber(): string
    {
        $prefix = 'DV-' . now()->format('Ym') . '-';

        return Cache::lock('delivery_voucher_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
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
