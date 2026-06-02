<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A general-ledger account in the chart of accounts (شجرة الحسابات). Journal
 * entry lines post against accounts; the account's `nature` decides whether a
 * debit increases or decreases its balance.
 */
class Account extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'name_en',
        'type',
        'nature',
        'currency',
        'parent_id',
        'opening_balance',
        'opening_balance_date',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'nature' => AccountDirection::class,
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'type', 'nature', 'currency', 'opening_balance', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Account {$this->name} was {$eventName}");
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * +1 for a debit-natured account (asset/expense), -1 for a credit-natured
     * one (liability/equity/revenue). Used to fold a (debit − credit) movement
     * into the account's running balance.
     */
    public function naturalSign(): int
    {
        return $this->nature === AccountDirection::Debit ? 1 : -1;
    }

    /**
     * Display label: "code — name" when a code exists, otherwise the name.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->code ? "{$this->code} — {$this->name}" : (string) $this->name;
    }
}
