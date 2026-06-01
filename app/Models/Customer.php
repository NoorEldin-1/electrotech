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

class Customer extends Model
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
        'address',
        'notes',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'contact_person', 'phone', 'email', 'tax_number'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function deliveryVouchers(): HasMany
    {
        return $this->hasMany(DeliveryVoucher::class);
    }

    /**
     * Ledger entries posted against this customer (debits from delivery
     * vouchers). Drives the customer account statement (كشف حساب العميل).
     */
    public function accountEntries(): MorphMany
    {
        return $this->morphMany(AccountEntry::class, 'party');
    }

    /**
     * Running account balance: positive means the customer owes us.
     */
    public function getBalanceAttribute(): float
    {
        return (float) $this->accountEntries()->sum('amount');
    }
}
