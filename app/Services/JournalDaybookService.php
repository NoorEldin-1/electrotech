<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * اليومية التحليلية — the analytical daybook (قائمة المواد.pptx سلايد 2). One
 * row per posted journal entry (date, serial, document number, description)
 * spread across a debit/credit column pair for each chosen account, plus the
 * entry's own debit/credit totals so nothing is lost when an entry touches an
 * account outside the selected columns.
 *
 * Aggregation happens in PHP — same portability choice as GeneralLedgerService.
 */
class JournalDaybookService
{
    /**
     * The most account columns a single daybook may show. Beyond this the
     * table stops being readable (and printable) on one landscape page.
     */
    public const MAX_COLUMNS = 6;

    /**
     * Build the daybook for a period.
     *
     * @param  array<int, int|string>  $accountIds  Accounts to render as columns; empty = the busiest accounts of the period.
     * @return array{
     *     accounts: Collection<int, Account>,
     *     rows: Collection<int, array{entry: JournalEntry, cells: array<int, array{debit: float, credit: float}>, total_debit: float, total_credit: float}>,
     *     column_totals: array<int, array{debit: float, credit: float}>,
     *     total_debit: float,
     *     total_credit: float,
     *     available_accounts: Collection<int, Account>
     * }
     */
    public function build(?Carbon $from = null, ?Carbon $to = null, array $accountIds = [], ?string $currency = null): array
    {
        $entries = $this->postedEntries($from, $to, $currency);
        $available = $this->accountsWithMovement($entries);

        $accounts = $this->resolveColumns($entries, $available, $accountIds);
        $columnIds = $accounts->pluck('id')->all();

        $columnTotals = array_fill_keys($columnIds, ['debit' => 0.0, 'credit' => 0.0]);
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        $rows = $entries->map(function (JournalEntry $entry) use ($columnIds, &$columnTotals, &$totalDebit, &$totalCredit): array {
            $cells = array_fill_keys($columnIds, ['debit' => 0.0, 'credit' => 0.0]);
            $rowDebit = 0.0;
            $rowCredit = 0.0;

            foreach ($entry->lines as $line) {
                $amount = (float) $line->amount;
                $side = $line->direction === AccountDirection::Debit ? 'debit' : 'credit';

                if ($side === 'debit') {
                    $rowDebit += $amount;
                } else {
                    $rowCredit += $amount;
                }

                if (! array_key_exists($line->account_id, $cells)) {
                    continue;
                }

                $cells[$line->account_id][$side] += $amount;
                $columnTotals[$line->account_id][$side] += $amount;
            }

            $totalDebit += $rowDebit;
            $totalCredit += $rowCredit;

            return [
                'entry' => $entry,
                'cells' => $cells,
                'total_debit' => round($rowDebit, 2),
                'total_credit' => round($rowCredit, 2),
            ];
        });

        return [
            'accounts' => $accounts,
            'rows' => $rows,
            'column_totals' => $columnTotals,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'available_accounts' => $available,
        ];
    }

    /**
     * Posted entries of the period, ordered the way the paper daybook reads:
     * by date, then by entry serial.
     *
     * @return Collection<int, JournalEntry>
     */
    private function postedEntries(?Carbon $from, ?Carbon $to, ?string $currency): Collection
    {
        return JournalEntry::query()
            ->where('status', JournalStatus::Posted->value)
            ->when($from, fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->when($currency, fn ($q, $c) => $q->where('currency', $c))
            ->with(['lines.account'])
            ->orderBy('entry_date')
            ->orderBy('entry_serial')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every account touched by the period's entries, ordered by code — the
     * pool the user picks columns from.
     *
     * @param  Collection<int, JournalEntry>  $entries
     * @return Collection<int, Account>
     */
    private function accountsWithMovement(Collection $entries): Collection
    {
        return $entries
            ->flatMap(fn (JournalEntry $entry) => $entry->lines->pluck('account'))
            ->filter()
            ->unique('id')
            ->sortBy(fn (Account $account) => [$account->code ?? '', $account->name])
            ->values();
    }

    /**
     * The account columns to render: the user's selection (capped), or — when
     * nothing is selected — the accounts carrying the largest movement in the
     * period, which is what an accountant would put on the sheet anyway.
     *
     * @param  Collection<int, JournalEntry>  $entries
     * @param  Collection<int, Account>  $available
     * @param  array<int, int|string>  $accountIds
     * @return Collection<int, Account>
     */
    private function resolveColumns(Collection $entries, Collection $available, array $accountIds): Collection
    {
        $selected = collect($accountIds)->map(fn ($id) => (int) $id)->filter()->unique();

        if ($selected->isNotEmpty()) {
            return $available
                ->filter(fn (Account $account) => $selected->contains($account->id))
                ->take(self::MAX_COLUMNS)
                ->values();
        }

        $movement = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $movement[$line->account_id] = ($movement[$line->account_id] ?? 0.0) + (float) $line->amount;
            }
        }

        arsort($movement);
        $busiest = array_slice(array_keys($movement), 0, self::MAX_COLUMNS);

        return $available
            ->filter(fn (Account $account) => in_array($account->id, $busiest, true))
            ->values();
    }
}
