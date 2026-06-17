<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'tax_number',
        'profit_tax_exempt',
        'address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'profit_tax_exempt' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'contact_person', 'phone', 'email', 'tax_number', 'profit_tax_exempt'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function additionVouchers(): HasMany
    {
        return $this->hasMany(AdditionVoucher::class);
    }

    /**
     * Supplier-file documents (slide 3): commercial registry, tax card,
     * 1%-exemption proof. Polymorphic — see Attachment::attachable().
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Ledger entries posted against this supplier (credits from addition
     * vouchers). Drives the supplier account statement (كشف حساب المورد).
     */
    public function accountEntries(): MorphMany
    {
        return $this->morphMany(AccountEntry::class, 'party');
    }

    /**
     * Running account balance: positive means we owe the supplier.
     */
    public function getBalanceAttribute(): float
    {
        return (float) $this->accountEntries()->sum('amount');
    }
}
