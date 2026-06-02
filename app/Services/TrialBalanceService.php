<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ميزان المراجعة — aggregates every active account into a trial balance
 * (سلايد 4): per-account total debit, total credit and balance. Grouped by
 * currency since the chart mixes EGP/USD/EUR accounts and no FX conversion is
 * applied yet.
 */
class TrialBalanceService
{
    public function __construct(
        private readonly GeneralLedgerService $ledger,
    ) {}

    /**
     * Per-account rows for a single currency (or all active accounts).
     *
     * @return Collection<int, array{account: Account, debit: float, credit: float, balance: float}>
     */
    public function build(?Carbon $asOf = null, ?string $currency = null): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->when($currency, fn ($q) => $q->where('currency', $currency))
            ->orderBy('code')
            ->orderBy('name')
            ->get()
            ->map(function (Account $account) use ($asOf): array {
                $totals = $this->ledger->totals($account, null, $asOf);

                return [
                    'account' => $account,
                    'debit' => $totals['debit'],
                    'credit' => $totals['credit'],
                    'balance' => $this->ledger->closingBalance($account, $asOf),
                ];
            })
            // Drop accounts with no activity and no opening balance.
            ->filter(fn (array $row): bool => $row['debit'] != 0.0 || $row['credit'] != 0.0 || $row['balance'] != 0.0)
            ->values();
    }

    /**
     * Trial balance grouped by currency, with per-currency totals and a
     * balanced flag (total debit movement == total credit movement).
     *
     * @return Collection<string, array{rows: Collection, total_debit: float, total_credit: float, balanced: bool}>
     */
    public function grouped(?Carbon $asOf = null): Collection
    {
        return $this->currencies()->mapWithKeys(function (string $currency) use ($asOf): array {
            $rows = $this->build($asOf, $currency);
            $totalDebit = round((float) $rows->sum('debit'), 2);
            $totalCredit = round((float) $rows->sum('credit'), 2);

            return [$currency => [
                'rows' => $rows,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balanced' => abs($totalDebit - $totalCredit) < 0.001,
            ]];
        })->filter(fn (array $group): bool => $group['rows']->isNotEmpty());
    }

    /**
     * Distinct currencies among active accounts.
     *
     * @return Collection<int, string>
     */
    public function currencies(): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency');
    }
}
