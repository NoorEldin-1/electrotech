<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single posting line of a journal entry: a debit or credit of `amount`
 * against `account`. The amount is always positive; `direction` carries the
 * sign relative to the account's nature.
 */
class JournalEntryLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'direction',
        'amount',
        'line_notes',
    ];

    protected function casts(): array
    {
        return [
            'direction' => AccountDirection::class,
            'amount' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
