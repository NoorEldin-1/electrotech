<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatementSection;
use App\Models\Account;
use Illuminate\Support\Carbon;

/**
 * قائمة الدخل — ماليات.pptx سلايد 3. Reproduces the client's own table, right
 * down to its two numeric columns (جزئى / كلى):
 *
 *   صافى المبيعات (حساب المبيعات − المردودات)      جزئى
 *   ( - ) تكلفة المبيعات                            جزئى
 *   مجمل الربح                                       كلى
 *   ( + ) ايرادات متنوعة                            جزئى
 *   ( + ) فروق العملة                               جزئى
 *   اجمالى الايرادات                                 كلى
 *   ( - ) م . عمومية                                جزئى
 *   ( - ) فروق العملة                               جزئى
 *   ( - ) مصروفات واهلاكات                          جزئى
 *   ( - ) اعتمادات مستندية سبق اقفالها              جزئى
 *   ( - ) فوائد مدينة                               جزئى
 *   اجمالى المصروفات                                 كلى
 *   صافى الربح                                       كلى
 *
 * The three subtotals follow the slide's own callouts:
 *   مجمل الربح       = صافى المبيعات − تكلفة البضاعة المباعة
 *   اجمالى الايرادات = ايرادات متنوعة + فروق العملة
 *   صافى الربح       = مجمل الربح + اجمالى الايرادات − اجمالى المصروفات
 *
 * A note on the two فروق العملة rows: the slide lists currency differences on
 * BOTH sides because one account can close either way. There is a single
 * account (or set of accounts) classified as FxDifferences; its net movement
 * is placed on the revenue row when it is a gain and on the expense row when
 * it is a loss, and the other row shows zero. That is the only way to honour
 * both rows without double counting the same money.
 *
 * Every figure is PERIOD MOVEMENT, never a lifetime balance: an income
 * statement describes a period.
 */
class IncomeStatementService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly OperatingStatementService $operating,
    ) {}

    /**
     * @return array{
     *     net_sales: array{sales: float, returns: float, net: float, sales_accounts: array<int, array{account: Account, amount: float}>, returns_accounts: array<int, array{account: Account, amount: float}>},
     *     cost_of_sales: float,
     *     cost_of_sales_rows: array<int, array{account: Account, amount: float}>,
     *     gross_profit: float,
     *     revenues: array{rows: array<int, array{label: string, amount: float, accounts: array<int, array{account: Account, amount: float}>}>, total: float},
     *     expenses: array{rows: array<int, array{label: string, amount: float, accounts: array<int, array{account: Account, amount: float}>}>, total: float},
     *     net_profit: float,
     *     from: ?Carbon,
     *     to: ?Carbon
     * }
     */
    public function build(?Carbon $from = null, ?Carbon $to = null): array
    {
        $salesAccounts = $this->rowsFor(StatementSection::Sales, $from, $to);
        $returnsAccounts = $this->rowsFor(StatementSection::SalesReturns, $from, $to);

        $sales = $this->sum($salesAccounts);
        $returns = $this->sum($returnsAccounts);
        $netSales = round($sales - $returns, 2);

        $operating = $this->operating->build($from, $to);
        $costOfSales = $operating['total'];
        $grossProfit = round($netSales - $costOfSales, 2);

        // فروق العملة: one net figure, routed to whichever side it fell on.
        $fxAccounts = $this->rowsFor(StatementSection::FxDifferences, $from, $to);
        $fxNet = $this->sum($fxAccounts);

        $otherRevenue = $this->rowsFor(StatementSection::OtherRevenue, $from, $to);
        $capitalGains = $this->rowsFor(StatementSection::CapitalGains, $from, $to);

        $revenueRows = [
            $this->line('other_revenue', $this->sum($otherRevenue), $otherRevenue),
            $this->line('capital_gains', $this->sum($capitalGains), $capitalGains),
            $this->line('fx_gain', max($fxNet, 0.0), $fxNet > 0 ? $fxAccounts : []),
        ];

        $generalAdmin = $this->rowsFor(StatementSection::GeneralAdminExpenses, $from, $to);
        $depreciation = $this->rowsFor(StatementSection::DepreciationExpenses, $from, $to);
        $closedLcs = $this->rowsFor(StatementSection::ClosedLettersOfCredit, $from, $to);
        $financeCost = $this->rowsFor(StatementSection::FinanceCost, $from, $to);

        $expenseRows = [
            $this->line('general_admin', $this->sum($generalAdmin), $generalAdmin),
            $this->line('fx_loss', max(-$fxNet, 0.0), $fxNet < 0 ? $fxAccounts : []),
            $this->line('depreciation', $this->sum($depreciation), $depreciation),
            $this->line('closed_letters_of_credit', $this->sum($closedLcs), $closedLcs),
            $this->line('finance_cost', $this->sum($financeCost), $financeCost),
        ];

        $totalRevenues = round(array_sum(array_column($revenueRows, 'amount')), 2);
        $totalExpenses = round(array_sum(array_column($expenseRows, 'amount')), 2);

        return [
            'net_sales' => [
                'sales' => $sales,
                'returns' => $returns,
                'net' => $netSales,
                'sales_accounts' => $salesAccounts,
                'returns_accounts' => $returnsAccounts,
            ],
            'cost_of_sales' => $costOfSales,
            'cost_of_sales_rows' => $operating['rows'],
            'gross_profit' => $grossProfit,
            'revenues' => ['rows' => $revenueRows, 'total' => $totalRevenues],
            'expenses' => ['rows' => $expenseRows, 'total' => $totalExpenses],
            'net_profit' => round($grossProfit + $totalRevenues - $totalExpenses, 2),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * صافى الربح for a period — the figure قائمة التدفقات النقدية starts from
     * (سلايد 8: "بداية القائمة بحساب صافى الربح (بيوخذ من قائمة الدخل)") and
     * that the balance sheet shows as أرباح الفترة inside equity.
     */
    public function netProfit(?Carbon $from = null, ?Carbon $to = null): float
    {
        return $this->build($from, $to)['net_profit'];
    }

    /**
     * @param  array<int, array{account: Account, amount: float}>  $accounts
     * @return array{label: string, amount: float, accounts: array<int, array{account: Account, amount: float}>}
     */
    private function line(string $label, float $amount, array $accounts): array
    {
        return ['label' => $label, 'amount' => round($amount, 2), 'accounts' => $accounts];
    }

    /**
     * @return array<int, array{account: Account, amount: float}>
     */
    private function rowsFor(StatementSection $section, ?Carbon $from, ?Carbon $to): array
    {
        return $this->balances
            ->sectionRoots($section)
            ->map(fn (Account $account): array => [
                'account' => $account,
                'amount' => $this->balances->rolledUpMovement($account, $from, $to),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{account: Account, amount: float}>  $rows
     */
    private function sum(array $rows): float
    {
        return round(array_sum(array_column($rows, 'amount')), 2);
    }
}
