<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\BalanceSheetService;
use App\Services\CashFlowStatementService;
use App\Services\IncomeStatementService;
use App\Services\JournalEntryService;
use App\Services\OperatingStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The four statements of ماليات.pptx, checked against figures worked out by
 * hand from the slides rather than from the implementation.
 */
class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = Carbon::parse('2026-01-01');
        $this->to = Carbon::parse('2026-12-31');
    }

    private function account(
        string $code,
        AccountType $type,
        StatementSection $section,
        ?AccountDirection $nature = null,
        float $opening = 0,
    ): Account {
        return Account::factory()->create([
            'code' => $code,
            'name' => 'ح/' . $code,
            'type' => $type,
            'nature' => $nature ?? $type->naturalDirection(),
            'statement_section' => $section,
            'opening_balance' => $opening,
            'currency' => 'EGP',
        ]);
    }

    private function postEntry(Account $debit, Account $credit, float $amount, string $date): void
    {
        $entry = JournalEntry::factory()->create(['entry_date' => $date]);

        JournalEntryLine::factory()->create(['journal_entry_id' => $entry->id, 'account_id' => $debit->id, 'direction' => AccountDirection::Debit, 'amount' => $amount]);
        JournalEntryLine::factory()->create(['journal_entry_id' => $entry->id, 'account_id' => $credit->id, 'direction' => AccountDirection::Credit, 'amount' => $amount]);

        app(JournalEntryService::class)->post($entry->fresh('lines'));
    }

    // ─────────────────────────────── سلايد 2 ───────────────────────────────

    public function test_operating_statement_sums_every_cost_of_sales_account(): void
    {
        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks);

        // The five components the slide lists, plus one general expense that
        // must NOT be pulled into cost of sales.
        foreach (['5070' => 100_000, '5010' => 40_000, '5020' => 25_000, '5050' => 15_000, '5100' => 20_000] as $code => $amount) {
            $expense = $this->account((string) $code, AccountType::Expense, StatementSection::CostOfSales);
            $this->postEntry($expense, $cash, (float) $amount, '2026-03-01');
        }

        $general = $this->account('5030', AccountType::Expense, StatementSection::GeneralAdminExpenses);
        $this->postEntry($general, $cash, 99_000, '2026-03-01');

        $statement = app(OperatingStatementService::class)->build($this->from, $this->to);

        $this->assertCount(5, $statement['rows']);
        $this->assertEqualsWithDelta(200_000.0, $statement['total'], 0.01);
    }

    // ─────────────────────────────── سلايد 3 ───────────────────────────────

    public function test_income_statement_reproduces_the_slide_subtotals(): void
    {
        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks);
        $sales = $this->account('4010', AccountType::Revenue, StatementSection::Sales);
        // مردودات المبيعات — إيراد بطبيعة مدينة
        $returns = $this->account('4060', AccountType::Revenue, StatementSection::SalesReturns, AccountDirection::Debit);
        $cogs = $this->account('5070', AccountType::Expense, StatementSection::CostOfSales);
        $other = $this->account('4020', AccountType::Revenue, StatementSection::OtherRevenue);
        $admin = $this->account('5030', AccountType::Expense, StatementSection::GeneralAdminExpenses);
        $interest = $this->account('5090', AccountType::Expense, StatementSection::FinanceCost);

        $this->postEntry($cash, $sales, 1_000_000, '2026-02-01');
        $this->postEntry($returns, $cash, 100_000, '2026-02-15');
        $this->postEntry($cogs, $cash, 600_000, '2026-03-01');
        $this->postEntry($cash, $other, 50_000, '2026-04-01');
        $this->postEntry($admin, $cash, 120_000, '2026-05-01');
        $this->postEntry($interest, $cash, 30_000, '2026-06-01');

        $s = app(IncomeStatementService::class)->build($this->from, $this->to);

        // صافى المبيعات = 1,000,000 − 100,000
        $this->assertEqualsWithDelta(900_000.0, $s['net_sales']['net'], 0.01);
        // مجمل الربح = 900,000 − 600,000
        $this->assertEqualsWithDelta(300_000.0, $s['gross_profit'], 0.01);
        // اجمالى الايرادات = 50,000
        $this->assertEqualsWithDelta(50_000.0, $s['revenues']['total'], 0.01);
        // اجمالى المصروفات = 120,000 + 30,000
        $this->assertEqualsWithDelta(150_000.0, $s['expenses']['total'], 0.01);
        // صافى الربح = 300,000 + 50,000 − 150,000
        $this->assertEqualsWithDelta(200_000.0, $s['net_profit'], 0.01);
    }

    public function test_currency_differences_land_on_the_gain_row_when_net_credit(): void
    {
        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks);
        $fx = $this->account('4040', AccountType::Revenue, StatementSection::FxDifferences);

        $this->postEntry($cash, $fx, 90_000, '2026-04-01'); // ربح
        $this->postEntry($fx, $cash, 25_000, '2026-07-01'); // خسارة

        $s = app(IncomeStatementService::class)->build($this->from, $this->to);

        $gain = collect($s['revenues']['rows'])->firstWhere('label', 'fx_gain');
        $loss = collect($s['expenses']['rows'])->firstWhere('label', 'fx_loss');

        $this->assertEqualsWithDelta(65_000.0, $gain['amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, $loss['amount'], 0.01);
    }

    public function test_currency_differences_land_on_the_loss_row_when_net_debit(): void
    {
        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks);
        $fx = $this->account('4040', AccountType::Revenue, StatementSection::FxDifferences);

        $this->postEntry($cash, $fx, 20_000, '2026-04-01');
        $this->postEntry($fx, $cash, 75_000, '2026-07-01');

        $s = app(IncomeStatementService::class)->build($this->from, $this->to);

        $this->assertEqualsWithDelta(0.0, collect($s['revenues']['rows'])->firstWhere('label', 'fx_gain')['amount'], 0.01);
        $this->assertEqualsWithDelta(55_000.0, collect($s['expenses']['rows'])->firstWhere('label', 'fx_loss')['amount'], 0.01);
    }

    public function test_income_statement_ignores_unposted_entries_and_other_periods(): void
    {
        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks);
        $sales = $this->account('4010', AccountType::Revenue, StatementSection::Sales);

        $this->postEntry($cash, $sales, 500_000, '2026-06-01');
        $this->postEntry($cash, $sales, 400_000, '2027-06-01'); // خارج الفترة

        // قيد مسودة — لا يُرحَّل
        $draft = JournalEntry::factory()->create(['entry_date' => '2026-06-02']);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $cash->id, 'direction' => AccountDirection::Debit, 'amount' => 999_000]);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $sales->id, 'direction' => AccountDirection::Credit, 'amount' => 999_000]);

        $s = app(IncomeStatementService::class)->build($this->from, $this->to);

        $this->assertEqualsWithDelta(500_000.0, $s['net_sales']['sales'], 0.01);
    }

    // ─────────────────────────── سلايدات 4، 5، 6 ───────────────────────────

    public function test_balance_sheet_equations_follow_the_slide(): void
    {
        $this->buildBalancedCompany();

        $b = app(BalanceSheetService::class)->build($this->to, $this->from);

        // الأصول المتداولة (200,000 نقدية + 300,000 مخزون) − التزامات 150,000
        $this->assertEqualsWithDelta(350_000.0, $b['working_capital'], 0.01);
        // + صافي الأصول الثابتة (1,000,000 − 250,000)
        $this->assertEqualsWithDelta(1_100_000.0, $b['total_investment'], 0.01);
        $this->assertTrue($b['balanced'], 'The balance sheet should balance: ' . $b['difference']);
    }

    public function test_fixed_assets_print_cost_accumulated_and_net(): void
    {
        $this->buildBalancedCompany();

        $rows = app(BalanceSheetService::class)->build($this->to, $this->from)['fixed_assets'];

        $this->assertEqualsWithDelta(1_000_000.0, $rows['cost'], 0.01);
        $this->assertEqualsWithDelta(250_000.0, $rows['accumulated'], 0.01);
        $this->assertEqualsWithDelta(750_000.0, $rows['net'], 0.01);
    }

    public function test_sub_accounts_roll_up_into_their_parent_row(): void
    {
        // سلايد 5: "مكتوب اسم الحساب الرئيسى ويتم وضع اى حساب فرعى داخل الحساب الرئيسى"
        $parent = $this->account('1300', AccountType::Asset, StatementSection::CurrentAssets, opening: 100_000);
        $child = $this->account('1301', AccountType::Asset, StatementSection::CurrentAssets, opening: 40_000);
        $child->forceFill(['parent_id' => $parent->id])->save();

        $rows = app(BalanceSheetService::class)->build($this->to, $this->from)['current_assets']['rows'];

        // One row, not two, and it carries the child's balance.
        $inventory = collect($rows)->filter(fn (array $r): bool => ($r['account']?->code ?? null) === '1300');

        $this->assertCount(1, $inventory);
        $this->assertEqualsWithDelta(140_000.0, $inventory->first()['amount'], 0.01);
        $this->assertNull(collect($rows)->firstWhere('account.code', '1301'));
    }

    public function test_memo_accounts_stay_out_of_the_equations_and_appear_as_a_footnote(): void
    {
        $this->buildBalancedCompany();

        // حاشية سلايد 6: «يوجد شيكات ضمانه بمبلغ ...»
        $this->account('1022', AccountType::Asset, StatementSection::Memo, opening: 250_000);

        $b = app(BalanceSheetService::class)->build($this->to, $this->from);

        $this->assertEqualsWithDelta(350_000.0, $b['working_capital'], 0.01);
        $this->assertTrue($b['balanced']);
        $this->assertCount(1, $b['memo']);
        $this->assertEqualsWithDelta(250_000.0, $b['memo'][0]['amount'], 0.01);
    }

    public function test_period_profit_flows_from_the_income_statement_into_equity(): void
    {
        $this->buildBalancedCompany();

        $cash = Account::where('code', '1010')->first();
        $sales = $this->account('4010', AccountType::Revenue, StatementSection::Sales);

        $this->postEntry($cash, $sales, 80_000, '2026-06-01');

        $b = app(BalanceSheetService::class)->build($this->to, $this->from);

        $this->assertEqualsWithDelta(80_000.0, $b['funding']['period_profit'], 0.01);
        // Cash rose by the same 80,000, so the sheet still balances.
        $this->assertTrue($b['balanced'], 'difference: ' . $b['difference']);
    }

    // ─────────────────────────── سلايدات 8 و 9 ────────────────────────────

    public function test_cash_flow_applies_the_four_cases_of_slide_nine(): void
    {
        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks, opening: 500_000);
        $inventory = $this->account('1300', AccountType::Asset, StatementSection::CurrentAssets, opening: 100_000);
        $receivable = $this->account('1230', AccountType::Asset, StatementSection::CurrentAssets, opening: 200_000);
        $supplier = $this->account('2010', AccountType::Liability, StatementSection::CurrentLiabilities, opening: 300_000);
        $notes = $this->account('2030', AccountType::Liability, StatementSection::CurrentLiabilities, opening: 400_000);

        // 1) أصل زاد   ⇒ يُطرح
        $this->postEntry($inventory, $cash, 50_000, '2026-03-01');
        // 2) أصل نقص   ⇒ يُضاف
        $this->postEntry($cash, $receivable, 30_000, '2026-04-01');
        // 3) التزام زاد ⇒ يُضاف
        $this->postEntry($inventory, $supplier, 70_000, '2026-05-01');
        // 4) التزام نقص ⇒ يُطرح
        $this->postEntry($notes, $cash, 20_000, '2026-06-01');

        $c = app(CashFlowStatementService::class)->build($this->from, $this->to);
        $rows = collect($c['working_capital'])->keyBy(fn (array $r): string => $r['account']->code);

        $this->assertSame(1, $rows['1300']['case']);
        $this->assertEqualsWithDelta(-120_000.0, $rows['1300']['amount'], 0.01);

        $this->assertSame(2, $rows['1230']['case']);
        $this->assertEqualsWithDelta(30_000.0, $rows['1230']['amount'], 0.01);

        $this->assertSame(3, $rows['2010']['case']);
        $this->assertEqualsWithDelta(70_000.0, $rows['2010']['amount'], 0.01);

        $this->assertSame(4, $rows['2030']['case']);
        $this->assertEqualsWithDelta(-20_000.0, $rows['2030']['amount'], 0.01);
    }

    public function test_cash_flow_reconciles_derived_closing_cash_with_the_ledger(): void
    {
        // "ويساوى رصيد النقدية اخر الفترة ويتم مطابقته مع الواقع" — the check
        // the slide itself ends on, and the strongest single assertion about
        // the whole statement.
        $this->buildBalancedCompany();

        $cash = Account::where('code', '1010')->first();
        $inventory = Account::where('code', '1300')->first();
        $accumulated = Account::where('code', '1450')->first();

        $sales = $this->account('4010', AccountType::Revenue, StatementSection::Sales);
        $cogs = $this->account('5070', AccountType::Expense, StatementSection::CostOfSales);
        $depreciation = $this->account('5110', AccountType::Expense, StatementSection::DepreciationExpenses);
        $admin = $this->account('5030', AccountType::Expense, StatementSection::GeneralAdminExpenses);

        $this->postEntry($cash, $sales, 400_000, '2026-02-01');
        $this->postEntry($cogs, $inventory, 150_000, '2026-03-01');
        $this->postEntry($admin, $cash, 60_000, '2026-04-01');
        // Non-cash: an expense whose other side is accumulated depreciation.
        $this->postEntry($depreciation, $accumulated, 90_000, '2026-12-31');

        $c = app(CashFlowStatementService::class)->build($this->from, $this->to);

        $this->assertTrue(
            $c['reconciled'],
            'Cash flow should reconcile; difference: ' . $c['reconciliation_difference'],
        );
        $this->assertEqualsWithDelta($c['actual_closing_cash'], $c['derived_closing_cash'], 0.01);
    }

    public function test_cash_flow_adds_back_non_cash_charges_by_default(): void
    {
        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks, opening: 500_000);
        $accumulated = $this->account('1450', AccountType::Asset, StatementSection::AccumulatedDepreciation, AccountDirection::Credit);
        $depreciation = $this->account('5110', AccountType::Expense, StatementSection::DepreciationExpenses);

        $this->postEntry($depreciation, $accumulated, 90_000, '2026-12-31');

        $c = app(CashFlowStatementService::class)->build($this->from, $this->to);
        $row = collect($c['adjustments'])->firstWhere('label', 'depreciation');

        $this->assertSame(1, $row['sign']);
        $this->assertEqualsWithDelta(90_000.0, $row['amount'], 0.01);
        // −90,000 profit + 90,000 add-back ⇒ no cash movement at all.
        $this->assertEqualsWithDelta(0.0, $c['net_change'], 0.01);
        $this->assertTrue($c['reconciled']);
    }

    public function test_cash_flow_follows_the_literal_slide_formula_when_configured(): void
    {
        config()->set('finance.cash_flow.add_back_non_cash', false);

        $cash = $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks, opening: 500_000);
        $accumulated = $this->account('1450', AccountType::Asset, StatementSection::AccumulatedDepreciation, AccountDirection::Credit);
        $depreciation = $this->account('5110', AccountType::Expense, StatementSection::DepreciationExpenses);

        $this->postEntry($depreciation, $accumulated, 90_000, '2026-12-31');

        $c = app(CashFlowStatementService::class)->build($this->from, $this->to);
        $row = collect($c['adjustments'])->firstWhere('label', 'depreciation');

        $this->assertSame(-1, $row['sign']);
        $this->assertEqualsWithDelta(-90_000.0, $row['amount'], 0.01);
        // And the statement then fails its own reconciliation, by design.
        $this->assertFalse($c['reconciled']);
    }

    // ────────────────────────── التصنيف والاشتقاق ──────────────────────────

    public function test_an_unclassified_account_still_lands_on_a_statement(): void
    {
        $account = Account::factory()->create([
            'code' => '9999',
            'type' => AccountType::Asset,
            'nature' => AccountDirection::Debit,
            'statement_section' => null,
            'opening_balance' => 25_000,
            'currency' => 'EGP',
        ]);

        $this->assertSame(StatementSection::CurrentAssets, $account->effectiveStatementSection());

        $rows = app(BalanceSheetService::class)->build($this->to, $this->from)['current_assets']['rows'];

        $this->assertNotNull(collect($rows)->first(fn (array $r): bool => ($r['account']?->code ?? null) === '9999'));
    }

    /**
     * A minimal company whose opening sheet already balances:
     *   assets 200,000 cash + 300,000 inventory + 1,000,000 fixed
     *   − 250,000 accumulated − 150,000 liabilities = 1,100,000 capital
     */
    private function buildBalancedCompany(): void
    {
        $this->account('1010', AccountType::Asset, StatementSection::CashAndBanks, opening: 200_000);
        $this->account('1300', AccountType::Asset, StatementSection::CurrentAssets, opening: 300_000);
        $building = $this->account('1420', AccountType::Asset, StatementSection::FixedAssets, opening: 1_000_000);

        $accumulated = $this->account('1450', AccountType::Asset, StatementSection::AccumulatedDepreciation, AccountDirection::Credit, 250_000);
        $accumulated->forceFill(['contra_of_account_id' => $building->id])->save();

        $this->account('2010', AccountType::Liability, StatementSection::CurrentLiabilities, opening: 150_000);
        $this->account('3020', AccountType::Equity, StatementSection::Equity, opening: 1_100_000);
    }
}
