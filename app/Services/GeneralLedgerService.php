<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * دفتر الأستاذ — builds the ledger of a single general-ledger account: the
 * opening balance (رصيد أول المدة) followed by every posted journal line with
 * a running balance computed by the account's nature. Computed in PHP so it
 * stays portable across MySQL and SQLite (same approach as
 * AccountStatementService for parties).
 */
class GeneralLedgerService
{
    /**
     * @return Collection<int, array{date: Carbon, entry_serial: ?int, entry_number: string, document_number: ?string, document_type: \App\Enums\DocumentType, description: ?string, debit: float, credit: float, balance: float}>
     */
    public function for(Account $account, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $sign = $account->naturalSign();
        $lines = $this->postedLines($account);

        $running = $this->openingBalance($account, $from);
        $rows = collect();

        foreach ($lines as $line) {
            $date = $line->journalEntry->entry_date;

            if ($from && $date->lt($from)) {
                continue;
            }
            if ($to && $date->gt($to)) {
                continue;
            }

            $debit = $line->direction === AccountDirection::Debit ? (float) $line->amount : 0.0;
            $credit = $line->direction === AccountDirection::Credit ? (float) $line->amount : 0.0;
            $running += $sign * ($debit - $credit);

            $rows->push([
                'date' => $date,
                'entry_serial' => $line->journalEntry->entry_serial,
                'entry_number' => $line->journalEntry->entry_number,
                'document_number' => $line->journalEntry->document_number,
                'document_type' => $line->journalEntry->document_type,
                'description' => $line->journalEntry->description,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($running, 2),
            ]);
        }

        return $rows;
    }

    /**
     * The account's opening balance for a period: its stored opening balance
     * plus any signed movement strictly before `$from`.
     */
    public function openingBalance(Account $account, ?Carbon $from = null): float
    {
        $opening = (float) $account->opening_balance;

        if (! $from) {
            return round($opening, 2);
        }

        $sign = $account->naturalSign();

        foreach ($this->postedLines($account) as $line) {
            if ($line->journalEntry->entry_date->lt($from)) {
                $opening += $sign * $this->movement($line);
            }
        }

        return round($opening, 2);
    }

    /**
     * The closing balance as of `$to` (inclusive), or all-time if null.
     */
    public function closingBalance(Account $account, ?Carbon $to = null): float
    {
        $sign = $account->naturalSign();
        $balance = (float) $account->opening_balance;

        foreach ($this->postedLines($account) as $line) {
            if ($to && $line->journalEntry->entry_date->gt($to)) {
                continue;
            }
            $balance += $sign * $this->movement($line);
        }

        return round($balance, 2);
    }

    /**
     * Period debit/credit totals (used for the "الإجمالي" row).
     *
     * @return array{debit: float, credit: float}
     */
    public function totals(Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $debit = 0.0;
        $credit = 0.0;

        foreach ($this->postedLines($account) as $line) {
            $date = $line->journalEntry->entry_date;
            if ($from && $date->lt($from)) {
                continue;
            }
            if ($to && $date->gt($to)) {
                continue;
            }

            if ($line->direction === AccountDirection::Debit) {
                $debit += (float) $line->amount;
            } else {
                $credit += (float) $line->amount;
            }
        }

        return ['debit' => round($debit, 2), 'credit' => round($credit, 2)];
    }

    /**
     * Posted lines for an account, ordered by entry date then id.
     *
     * @return Collection<int, JournalEntryLine>
     */
    private function postedLines(Account $account): Collection
    {
        return JournalEntryLine::query()
            ->where('account_id', $account->getKey())
            ->whereHas('journalEntry', fn (Builder $q) => $q->where('status', JournalStatus::Posted->value))
            ->with('journalEntry')
            ->get()
            // Single composite key (date then id) — the multi-criteria array
            // form of sortBy() does not order reliably here.
            ->sortBy(fn (JournalEntryLine $l) => ($l->journalEntry->entry_date->timestamp * 1_000_000) + $l->id)
            ->values();
    }

    /**
     * Signed (debit − credit) movement of a single line.
     */
    private function movement(JournalEntryLine $line): float
    {
        return $line->direction === AccountDirection::Debit
            ? (float) $line->amount
            : -(float) $line->amount;
    }
}
