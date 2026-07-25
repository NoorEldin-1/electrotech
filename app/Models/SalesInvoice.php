<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SalesInvoicingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * فاتورة مبيعات — the tax document issued against a delivery voucher
 * (سلايد 10). Its number is entered by hand: it belongs to the tax invoice
 * book / e-invoicing portal, not to this system.
 *
 * IMPORTANT — a sales invoice writes NO accounting entry. Activating the
 * delivery voucher already debits the customer with the delivery value
 * (see DeliveryVoucherService::activateIfFullyApproved); posting the invoice
 * too would double the customer's balance. The invoice is a tax / control
 * document that answers one question: was this delivery invoiced or not.
 */
class SalesInvoice extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'delivery_voucher_id',
        'customer_id',
        'invoice_date',
        'amount',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // The customer is a snapshot of the voucher's customer, kept on the
        // invoice so it can be filtered and reported on directly.
        static::creating(function (SalesInvoice $invoice): void {
            if ($invoice->customer_id === null) {
                $invoice->customer_id = $invoice->deliveryVoucher?->customer_id;
            }
        });

        // Keep the voucher's invoiced_value / invoicing_status true whichever
        // path touched the invoice (service, Filament edit, delete, restore).
        $sync = function (SalesInvoice $invoice): void {
            $voucher = $invoice->deliveryVoucher()->first();

            if ($voucher instanceof DeliveryVoucher) {
                app(SalesInvoicingService::class)->recalculate($voucher);
            }
        };

        static::saved($sync);
        static::deleted($sync);
        static::restored($sync);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_number', 'delivery_voucher_id', 'customer_id', 'amount', 'invoice_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Sales invoice {$this->invoice_number} was {$eventName}");
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
}
