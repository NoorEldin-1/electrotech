<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;

/**
 * A sensible opening period for the finance report screens.
 *
 * E2E report §5.4: اليومية التحليلية and دفتر الأستاذ both opened on the
 * current calendar month. Posting is not evenly spread across months — open a
 * report early in a month, or in any month the accountant has not posted into
 * yet, and the screen greets you with an empty table and no hint that you are
 * simply looking at the wrong window. Every tester reads that as "the report
 * is broken".
 *
 * So: open on the current month when it holds posted entries, and otherwise on
 * the month of the most recent posted entry — the last period that has
 * something to show. With an empty ledger it falls back to the current month,
 * which is then honestly empty.
 */
trait DefaultsToPeriodWithLedgerData
{
    /**
     * @return array{0: string, 1: string} [from, to] as Y-m-d strings
     */
    protected function defaultLedgerPeriod(): array
    {
        $now = Carbon::now();

        $hasEntriesThisMonth = JournalEntry::query()
            ->where('status', JournalStatus::Posted)
            ->whereBetween('entry_date', [
                $now->copy()->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ])
            ->exists();

        if (! $hasEntriesThisMonth) {
            $latest = JournalEntry::query()
                ->where('status', JournalStatus::Posted)
                ->max('entry_date');

            if ($latest) {
                $now = Carbon::parse($latest);
            }
        }

        return [
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ];
    }
}
