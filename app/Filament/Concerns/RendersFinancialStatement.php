<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use App\Services\AccountBalanceService;
use Illuminate\Support\Carbon;

/**
 * Shared plumbing for the four financial-statement pages (ماليات.pptx): the
 * period filter, its default, and the balance cache lifecycle.
 *
 * The default period is the FINANCIAL YEAR, not the current month the other
 * finance reports open on. These statements are the annual closing set — the
 * deck's own title is "ما بعد ميزان المراجعة مرتبط بالاقفال السنوى" — and a
 * one-month income statement is nearly always the wrong question. Following
 * the same reasoning as DefaultsToPeriodWithLedgerData, the year chosen is the
 * one the ledger actually has entries in, so the screen never opens empty on a
 * freshly seeded system.
 */
trait RendersFinancialStatement
{
    /** Start of the period (Y-m-d), bound to the date input. */
    public ?string $from = null;

    /** End of the period (Y-m-d), bound to the date input. */
    public ?string $to = null;

    public function mount(): void
    {
        [$from, $to] = $this->defaultFinancialYear();

        $this->from ??= $from;
        $this->to ??= $to;
    }

    /**
     * @return array{0: string, 1: string} [from, to] as Y-m-d strings
     */
    protected function defaultFinancialYear(): array
    {
        $year = Carbon::now();

        $hasEntriesThisYear = JournalEntry::query()
            ->where('status', JournalStatus::Posted)
            ->whereBetween('entry_date', [
                $year->copy()->startOfYear()->toDateString(),
                $year->copy()->endOfYear()->toDateString(),
            ])
            ->exists();

        if (! $hasEntriesThisYear) {
            $latest = JournalEntry::query()
                ->where('status', JournalStatus::Posted)
                ->max('entry_date');

            if ($latest) {
                $year = Carbon::parse($latest);
            }
        }

        return [
            $year->copy()->startOfYear()->toDateString(),
            $year->copy()->endOfYear()->toDateString(),
        ];
    }

    protected function fromDate(): ?Carbon
    {
        return $this->from ? Carbon::parse($this->from) : null;
    }

    protected function toDate(): ?Carbon
    {
        return $this->to ? Carbon::parse($this->to) : null;
    }

    /**
     * A statement page is a long-lived Livewire component, and the balance
     * service memoises totals per cut-off date. Clearing it before each build
     * is what makes "change the period, see new numbers" — and "post an entry
     * in another tab, refresh" — actually work.
     */
    protected function freshBalances(): AccountBalanceService
    {
        $balances = app(AccountBalanceService::class);
        $balances->flush();

        return $balances;
    }
}
