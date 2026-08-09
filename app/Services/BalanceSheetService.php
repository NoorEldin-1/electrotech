<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatementSection;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * قائمة المركز المالى — ماليات.pptx سلايدات 4، 5، 6.
 *
 * The equations are the client's own (سلايد 4):
 *
 *     الاصول المتداولة − الالتزامات المتداولة = راس المال العامل
 *     راس المال العامل + الاصول الثابتة        = اجمالى الاستثمار
 *
 * and the funding half from the photographed sheet (سلايد 6):
 *
 *     رأس المال + الاحتياطيات والمخصصات + جارى شركاء + أرباح الفترة
 *                                              = اجمالى التمويل
 *
 * The statement balances when اجمالى الاستثمار == اجمالى التمويل; the screen
 * says so out loud rather than leaving the reader to add it up.
 *
 * Three details are taken literally from the deck:
 *
 *  1. سلايد 5 — a row is a MAIN account and its sub-accounts are folded into
 *     it, so rows come from AccountBalanceService::sectionRoots().
 *  2. سلايد 6 — fixed assets print in three columns: التكلفة / مجمع الإهلاك /
 *     الصافى, matched through Account::contra_of_account_id.
 *  3. سلايد 7 — a party control account is not shown as one pooled balance.
 *     It is split by party balance sign: debit customers on the asset side,
 *     credit customers (دفعات مقدمة) on the liability side, and likewise for
 *     suppliers. Whatever the sub-ledger fails to explain is carried as an
 *     explicit reconciliation row instead of quietly unbalancing the sheet.
 */
class BalanceSheetService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly IncomeStatementService $income,
        private readonly PartyReclassificationService $parties,
    ) {}

    /**
     * @param  Carbon|null  $asOf  the balance-sheet date (31 ديسمبر …)
     * @param  Carbon|null  $periodFrom  start of the financial period, used to
     *                                   compute أرباح الفترة. Defaults to the
     *                                   start of $asOf's year.
     * @return array<string, mixed>
     */
    public function build(?Carbon $asOf = null, ?Carbon $periodFrom = null): array
    {
        $asOf ??= Carbon::today();
        $periodFrom ??= $asOf->copy()->startOfYear();

        $fixed = $this->fixedAssets($asOf);
        $currentAssets = $this->currentAssets($asOf);
        $currentLiabilities = $this->currentLiabilities($asOf);

        $workingCapital = round($currentAssets['total'] - $currentLiabilities['total'], 2);
        $totalInvestment = round($workingCapital + $fixed['net'], 2);

        $periodProfit = $this->income->netProfit($periodFrom, $asOf);
        $funding = $this->funding($asOf, $periodProfit);

        return [
            'as_of' => $asOf,
            'period_from' => $periodFrom,
            'fixed_assets' => $fixed,
            'current_assets' => $currentAssets,
            'current_liabilities' => $currentLiabilities,
            'working_capital' => $workingCapital,
            'total_investment' => $totalInvestment,
            'funding' => $funding,
            // The sheet is only trustworthy if the two halves meet.
            'balanced' => abs($totalInvestment - $funding['total']) < 0.01,
            'difference' => round($totalInvestment - $funding['total'], 2),
            'memo' => $this->memoLines($asOf),
        ];
    }

    /**
     * الأصول طويلة الأجل بثلاثة أعمدة (سلايد 6). Land carries no accumulated
     * depreciation and simply shows cost == net.
     *
     * @return array{rows: array<int, array{account: ?Account, label: string, cost: float, accumulated: float, net: float}>, cost: float, accumulated: float, net: float}
     */
    public function fixedAssets(Carbon $asOf): array
    {
        $assets = $this->balances->sectionRoots(StatementSection::FixedAssets);
        $contra = $this->balances->inSection(StatementSection::AccumulatedDepreciation);

        $rows = [];
        $linkedContraIds = [];

        foreach ($assets as $asset) {
            $familyIds = $this->balances->family($asset)->pluck('id')->all();

            $matched = $contra->filter(
                fn (Account $c): bool => in_array($c->contra_of_account_id, $familyIds, true)
            );

            $linkedContraIds = array_merge($linkedContraIds, $matched->pluck('id')->all());

            $cost = $this->balances->rolledUpClosing($asset, $asOf);
            $accumulated = round(
                $matched->sum(fn (Account $c): float => $this->balances->rolledUpClosing($c, $asOf)),
                2,
            );

            $rows[] = [
                'account' => $asset,
                'label' => $asset->name,
                'cost' => $cost,
                'accumulated' => $accumulated,
                'net' => round($cost - $accumulated, 2),
            ];
        }

        // An accumulated-depreciation account nobody linked to an asset would
        // otherwise vanish and overstate net assets. Surface it as its own row
        // so the gap is visible and fixable from the account form.
        $unlinked = $contra->reject(fn (Account $c): bool => in_array($c->id, $linkedContraIds, true));

        if ($unlinked->isNotEmpty()) {
            $amount = round($unlinked->sum(fn (Account $c): float => $this->balances->rolledUpClosing($c, $asOf)), 2);

            if (abs($amount) >= 0.005) {
                $rows[] = [
                    'account' => null,
                    'label' => __('resources.balance_sheet.unlinked_accumulated_depreciation'),
                    'cost' => 0.0,
                    'accumulated' => $amount,
                    'net' => round(-$amount, 2),
                ];
            }
        }

        return [
            'rows' => $rows,
            'cost' => round(array_sum(array_column($rows, 'cost')), 2),
            'accumulated' => round(array_sum(array_column($rows, 'accumulated')), 2),
            'net' => round(array_sum(array_column($rows, 'net')), 2),
        ];
    }

    /**
     * الأصول المتداولة — every current-asset and cash account, with party
     * control accounts replaced by their debit-side reclassification.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: float}
     */
    public function currentAssets(Carbon $asOf): array
    {
        $rows = $this->sectionRows(
            [StatementSection::CurrentAssets, StatementSection::CashAndBanks],
            $asOf,
            wantDebitSide: true,
        );

        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    /**
     * الالتزامات المتداولة — with party control accounts replaced by their
     * credit-side reclassification (عملاء – دفعات مقدمة، موردون دائنة).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: float}
     */
    public function currentLiabilities(Carbon $asOf): array
    {
        $rows = $this->sectionRows(
            [StatementSection::CurrentLiabilities],
            $asOf,
            wantDebitSide: false,
        );

        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    /**
     * «يتم تمويلة على النحو التالى» (سلايد 6): equity, reserves and provisions,
     * partners' current account, and the period's profit taken straight from
     * the income statement.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: float, period_profit: float}
     */
    public function funding(Carbon $asOf, float $periodProfit): array
    {
        $rows = [];

        foreach ([StatementSection::Equity, StatementSection::Provisions, StatementSection::PartnersCurrentAccount] as $section) {
            foreach ($this->balances->sectionRoots($section) as $account) {
                $rows[] = [
                    'account' => $account,
                    'label' => $account->name,
                    'amount' => $this->balances->rolledUpClosing($account, $asOf),
                    'section' => $section,
                    'kind' => 'account',
                ];
            }
        }

        // أرباح الفترة is computed, never read from an account — which is why
        // ح/صافي الربح is seeded as StatementSection::Excluded. Reading both
        // would count the same profit twice.
        $rows[] = [
            'account' => null,
            'label' => __('resources.balance_sheet.period_profit'),
            'amount' => $periodProfit,
            'section' => StatementSection::Equity,
            'kind' => 'period_profit',
        ];

        return [
            'rows' => $rows,
            'total' => round(array_sum(array_column($rows, 'amount')), 2),
            'period_profit' => $periodProfit,
        ];
    }

    /**
     * The footnote under the sheet — سلايد 6 prints "يوجد شيكات ضمانه بمبلغ
     * 000" below the totals. Memo accounts stay out of every equation.
     *
     * @return array<int, array{account: Account, amount: float}>
     */
    public function memoLines(Carbon $asOf): array
    {
        return $this->balances
            ->sectionRoots(StatementSection::Memo)
            ->map(fn (Account $a): array => [
                'account' => $a,
                'amount' => $this->balances->rolledUpClosing($a, $asOf),
            ])
            ->filter(fn (array $row): bool => abs($row['amount']) >= 0.005)
            ->values()
            ->all();
    }

    /**
     * Rows of one side of the sheet. A normal account contributes one row. A
     * party control account contributes the reclassified half that belongs on
     * THIS side plus, on its own natural side only, a reconciliation row for
     * whatever the sub-ledger does not explain.
     *
     * @param  array<int, StatementSection>  $sections
     * @param  bool  $wantDebitSide  true for the asset side, false for liabilities
     * @return array<int, array<string, mixed>>
     */
    private function sectionRows(array $sections, Carbon $asOf, bool $wantDebitSide): array
    {
        $rows = [];

        foreach ($this->balances->sectionRoots(...$sections) as $account) {
            // Party control accounts are handled once per party TYPE below,
            // never per account: a company with both مورد محلي and مورد خارجي
            // has two control accounts but only one supplier sub-ledger, and
            // splitting it per account would count every supplier twice.
            if ($account->party_control !== null) {
                continue;
            }

            $rows[] = [
                'account' => $account,
                'label' => $account->name,
                'amount' => $this->balances->rolledUpClosing($account, $asOf),
                'kind' => 'account',
            ];
        }

        return array_merge($rows, $this->partyRows($asOf, $wantDebitSide));
    }

    /**
     * سلايد 7 in code — the reclassified party rows this side of the sheet
     * receives, one pair per party type.
     *
     * The reconciliation row carries whatever the sub-ledger does not explain,
     * on whichever side that difference falls, so the sheet keeps balancing to
     * the general ledger while the split stays visible. Together the rows
     * always sum to the control accounts' own balance, which is why splitting
     * cannot distort working capital.
     *
     * @return array<int, array<string, mixed>>
     */
    private function partyRows(Carbon $asOf, bool $wantDebitSide): array
    {
        $rows = [];

        foreach ($this->partyControlAccounts() as $partyType => $controlAccounts) {
            $split = $partyType === 'customer'
                ? $this->parties->customers($asOf)
                : $this->parties->suppliers($asOf);

            $rows[] = [
                'account' => $controlAccounts->first(),
                'label' => __("resources.balance_sheet.party.{$partyType}." . ($wantDebitSide ? 'debit' : 'credit')),
                'amount' => $wantDebitSide ? $split['debit'] : $split['credit'],
                'kind' => 'party_split',
                'party_model' => $partyType === 'customer' ? Customer::class : Supplier::class,
                'party_control' => $partyType,
                'parties' => $wantDebitSide ? $split['debit_parties'] : $split['credit_parties'],
            ];

            $variance = $this->partyVariance($controlAccounts, $split['net'], $asOf);

            // The difference lands on the side its own sign points to: a
            // debit-positive variance is an asset, a negative one a liability.
            $belongsHere = $wantDebitSide ? $variance > 0 : $variance < 0;

            if (abs($variance) >= 0.005 && $belongsHere) {
                $rows[] = [
                    'account' => $controlAccounts->first(),
                    'label' => __('resources.balance_sheet.party.reconciliation', [
                        'account' => $controlAccounts->pluck('name')->implode(' + '),
                    ]),
                    'amount' => round(abs($variance), 2),
                    'kind' => 'party_reconciliation',
                ];
            }
        }

        return $rows;
    }

    /**
     * How much of the control accounts' combined balance the party sub-ledger
     * does not account for, expressed debit-positive.
     *
     * @param  Collection<int, Account>  $controlAccounts
     */
    private function partyVariance(Collection $controlAccounts, float $subLedgerNet, Carbon $asOf): float
    {
        $glDebitPositive = $controlAccounts->sum(function (Account $account) use ($asOf): float {
            $closing = $this->balances->rolledUpClosing($account, $asOf);

            // A liability control account closes credit-positive; flip it so
            // both sides are compared in the same direction as the sub-ledger.
            return $this->isAssetSide($account) ? $closing : -$closing;
        });

        return round($glDebitPositive - $subLedgerNet, 2);
    }

    /**
     * Control accounts grouped by the sub-ledger they control.
     *
     * @return Collection<string, Collection<int, Account>>
     */
    private function partyControlAccounts(): Collection
    {
        return $this->balances->accounts()
            ->filter(fn (Account $a): bool => in_array($a->party_control, ['customer', 'supplier'], true))
            ->groupBy('party_control');
    }

    private function isAssetSide(Account $account): bool
    {
        return in_array($account->effectiveStatementSection(), [
            StatementSection::CurrentAssets,
            StatementSection::CashAndBanks,
            StatementSection::FixedAssets,
        ], true);
    }
}
