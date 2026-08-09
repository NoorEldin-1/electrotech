<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
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
        'statement_section',
        'currency',
        'parent_id',
        'contra_of_account_id',
        'party_control',
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
            'statement_section' => StatementSection::class,
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'type', 'nature', 'statement_section', 'currency', 'opening_balance', 'is_active'])
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
     * For an accumulated-depreciation account: the fixed asset it is deducted
     * from, so the balance sheet can print التكلفة / مجمع الإهلاك / الصافى
     * side by side (سلايد 6).
     */
    public function contraOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contra_of_account_id');
    }

    /** The contra accounts (accumulated depreciation) pointing at this asset. */
    public function contraAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'contra_of_account_id');
    }

    /**
     * The account's financial-statement section, falling back to the section
     * derived from its type when the accountant has not classified it yet.
     * Statements must always call this, never the raw column, so that an
     * unclassified account still appears somewhere defensible.
     */
    public function effectiveStatementSection(): StatementSection
    {
        return $this->statement_section
            ?? StatementSection::defaultForType($this->type);
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
