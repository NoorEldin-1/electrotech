<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A double-entry journal entry (قيد يومية). Header for a balanced set of
 * lines. Once posted it is immutable and feeds the general ledger and trial
 * balance.
 */
class JournalEntry extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'entry_number',
        'document_number',
        'document_type',
        'entry_date',
        'description',
        'status',
        'currency',
        'total_debit',
        'total_credit',
        'notes',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => JournalStatus::class,
            'entry_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['entry_number', 'document_number', 'document_type', 'entry_date', 'status', 'total_debit', 'total_credit', 'posted_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Journal entry {$this->entry_number} was {$eventName}");
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->status === JournalStatus::Posted;
    }

    public function isDraft(): bool
    {
        return $this->status === JournalStatus::Draft;
    }

    public function linesDebitTotal(): float
    {
        return (float) $this->lines->where('direction', AccountDirection::Debit)->sum('amount');
    }

    public function linesCreditTotal(): float
    {
        return (float) $this->lines->where('direction', AccountDirection::Credit)->sum('amount');
    }

    /**
     * A journal is balanced when it has lines and total debits equal total
     * credits (within a one-cent tolerance for float rounding).
     */
    public function isBalanced(): bool
    {
        $this->loadMissing('lines');

        return $this->lines->count() >= 2
            && abs($this->linesDebitTotal() - $this->linesCreditTotal()) < 0.001;
    }

    /**
     * Recompute and persist the cached debit/credit totals from the lines.
     * Called after the entry and its lines are saved.
     */
    public function recalculateTotals(): void
    {
        $this->loadMissing('lines');

        $this->forceFill([
            'total_debit' => $this->linesDebitTotal(),
            'total_credit' => $this->linesCreditTotal(),
        ])->saveQuietly();
    }

    /**
     * Generate a unique entry number with a per-document-type monthly
     * sequence: {PV|RV|JV}-YYYYMM-XXXX. Redis lock makes read→increment
     * race-safe; the suffix is parsed in PHP to stay portable.
     */
    public static function generateEntryNumber(DocumentType $type): string
    {
        $prefix = $type->prefix() . '-' . now()->format('Ym') . '-';

        return Cache::lock('journal_entry_seq:' . $prefix, 5)->block(3, function () use ($prefix) {
            $latest = static::query()
                ->where('entry_number', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->value('entry_number');

            $seq = $latest ? (int) substr($latest, strlen($prefix)) : 0;

            return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
