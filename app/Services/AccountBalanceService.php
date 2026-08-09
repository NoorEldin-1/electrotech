<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\JournalStatus;
use App\Enums\StatementSection;
use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The balance engine behind the four financial statements (ماليات.pptx).
 *
 * GeneralLedgerService already answers "what is this account's balance", but
 * it loads every posted line of an account per call. A statement asks the same
 * question of ~60 accounts at two different dates at once, which would be ~120
 * full scans. This service answers it in ONE grouped query per cut-off date
 * and keeps the result in memory for the request.
 *
 * Two different numbers matter and are deliberately kept apart:
 *
 *   movement(from, to)  — the signed activity of a period. This is what the
 *                         income statement and the operating statement use:
 *                         "المبيعات عن الفترة", never a lifetime total.
 *   closing(asOf)       — opening balance + all activity up to a date. This is
 *                         what the balance sheet uses, and the two ends the
 *                         cash-flow statement subtracts from each other.
 */
class AccountBalanceService
{
    /**
     * Cached (debit, credit) totals per account, keyed by cut-off date.
     *
     * @var array<string, array<int, array{debit: float, credit: float}>>
     */
    private array $cumulative = [];

    /** @var Collection<int, Account>|null */
    private ?Collection $accounts = null;

    /**
     * Reset the memoised totals. Livewire keeps a page object alive across
     * requests, so a statement page must start from fresh numbers whenever the
     * user changes the period or new entries were posted meanwhile.
     */
    public function flush(): void
    {
        $this->cumulative = [];
        $this->accounts = null;
    }

    /**
     * Every account that can appear on a statement, eager enough to walk the
     * hierarchy without extra queries.
     *
     * @return Collection<int, Account>
     */
    public function accounts(): Collection
    {
        return $this->accounts ??= Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('name')
            ->get();
    }

    /**
     * Accounts belonging to a statement section (using the effective section,
     * so unclassified accounts are still placed).
     *
     * @return Collection<int, Account>
     */
    public function inSection(StatementSection ...$sections): Collection
    {
        return $this->accounts()
            ->filter(fn (Account $a): bool => in_array($a->effectiveStatementSection(), $sections, true))
            ->values();
    }

    /**
     * Signed movement of one account over a period, folded by its nature: a
     * debit-natured account grows on debits, a credit-natured one on credits.
     * Opening balance is NOT included — this is activity, not position.
     */
    public function movement(Account $account, ?Carbon $from, ?Carbon $to): float
    {
        $upTo = $this->totals($to)[$account->getKey()] ?? ['debit' => 0.0, 'credit' => 0.0];
        $before = $from
            ? ($this->totals($from->copy()->subDay())[$account->getKey()] ?? ['debit' => 0.0, 'credit' => 0.0])
            : ['debit' => 0.0, 'credit' => 0.0];

        $debit = $upTo['debit'] - $before['debit'];
        $credit = $upTo['credit'] - $before['credit'];

        return round($account->naturalSign() * ($debit - $credit), 2);
    }

    /**
     * Position of one account at a date: its stored opening balance plus every
     * posted movement up to and including `$asOf`.
     */
    public function closing(Account $account, ?Carbon $asOf): float
    {
        $totals = $this->totals($asOf)[$account->getKey()] ?? ['debit' => 0.0, 'credit' => 0.0];

        return round(
            (float) $account->opening_balance
            + $account->naturalSign() * ($totals['debit'] - $totals['credit']),
            2,
        );
    }

    /**
     * Movement of an account INCLUDING its descendants — the roll-up سلايد 5
     * asks for: "مكتوب اسم الحساب الرئيسى ويتم وضع اى حساب فرعى داخل الحساب
     * الرئيسى".
     */
    public function rolledUpMovement(Account $account, ?Carbon $from, ?Carbon $to): float
    {
        return round(
            $this->family($account)->sum(fn (Account $a): float => $this->movement($a, $from, $to)),
            2,
        );
    }

    /** Closing balance of an account including its descendants (سلايد 5). */
    public function rolledUpClosing(Account $account, ?Carbon $asOf): float
    {
        return round(
            $this->family($account)->sum(fn (Account $a): float => $this->closing($a, $asOf)),
            2,
        );
    }

    /**
     * The rows of a statement section: the accounts that head their own line,
     * i.e. those whose parent is NOT itself in the same section. A sub-account
     * never gets its own row — its value is folded into its parent's.
     *
     * @return Collection<int, Account>
     */
    public function sectionRoots(StatementSection ...$sections): Collection
    {
        $inSection = $this->inSection(...$sections);
        $ids = $inSection->pluck('id')->all();

        return $inSection
            ->filter(fn (Account $a): bool => $a->parent_id === null || ! in_array($a->parent_id, $ids, true))
            ->values();
    }

    /**
     * An account together with every descendant of it, so a roll-up survives a
     * chart nested more than one level deep.
     *
     * @return Collection<int, Account>
     */
    public function family(Account $account): Collection
    {
        $family = collect([$account]);
        $frontier = [$account->getKey()];

        // Bounded by the depth of the chart; the account list is already in
        // memory so this walks arrays, not the database.
        while ($frontier !== []) {
            $children = $this->accounts()
                ->filter(fn (Account $a): bool => in_array($a->parent_id, $frontier, true))
                // Guard against a chart with a cycle, which would otherwise spin here.
                ->reject(fn (Account $a): bool => $family->contains('id', $a->getKey()))
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $family = $family->concat($children);
            $frontier = $children->pluck('id')->all();
        }

        return $family;
    }

    /**
     * Cumulative posted debit/credit per account up to a date — one grouped
     * query, memoised per cut-off.
     *
     * @return array<int, array{debit: float, credit: float}>
     */
    private function totals(?Carbon $asOf): array
    {
        $key = $asOf?->toDateString() ?? 'all';

        if (array_key_exists($key, $this->cumulative)) {
            return $this->cumulative[$key];
        }

        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->when($asOf, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString()))
            ->groupBy('journal_entry_lines.account_id', 'journal_entry_lines.direction')
            ->select(
                'journal_entry_lines.account_id',
                'journal_entry_lines.direction',
                DB::raw('SUM(journal_entry_lines.amount) as total'),
            )
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $accountId = (int) $row->account_id;
            $totals[$accountId] ??= ['debit' => 0.0, 'credit' => 0.0];

            $side = $row->direction === AccountDirection::Debit->value ? 'debit' : 'credit';
            $totals[$accountId][$side] += (float) $row->total;
        }

        return $this->cumulative[$key] = $totals;
    }
}
