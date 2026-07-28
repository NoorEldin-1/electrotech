<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * إقفال مركز التكلفة — one closing of an operation's cost centre into the cost
 * of goods sold account (Financial Department سلايد 12).
 *
 * `amount` is positive for a closing and negative for the reversal that undoes
 * one, so the operation's closed value is simply SUM(amount) and never needs a
 * status column.
 */
class CostCenterClosing extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'project_id',
        'delivery_voucher_id',
        'journal_entry_id',
        'reverses_id',
        'amount',
        'is_automatic',
        'notes',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_automatic' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'amount', 'journal_entry_id', 'reverses_id', 'is_automatic', 'closed_at'])
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Cost centre closing #{$this->id} was {$eventName}");
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveryVoucher(): BelongsTo
    {
        return $this->belongsTo(DeliveryVoucher::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** The closing this row reverses (set on reversal rows only). */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** The reversal that undid this closing, if any. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversal()->exists();
    }
}
