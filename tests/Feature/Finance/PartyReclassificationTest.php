<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\BalanceSheetService;
use App\Services\PartyReclassificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ماليات.pptx سلايد 7 — customers and suppliers split by balance side, and the
 * balance sheet showing the two halves on opposite sides.
 */
class PartyReclassificationTest extends TestCase
{
    use RefreshDatabase;

    private function entry(Model $party, float $amount, AccountDirection $direction, string $date = '2026-06-01'): void
    {
        AccountEntry::create([
            'party_type' => $party->getMorphClass(),
            'party_id' => $party->getKey(),
            'entry_date' => $date,
            'direction' => $direction,
            'amount' => $amount,
        ]);
    }

    public function test_customers_split_by_the_sign_of_their_balance(): void
    {
        $owing = Customer::factory()->create(['name' => 'عميل مدين']);
        $prepaid = Customer::factory()->create(['name' => 'عميل دائن']);

        $this->entry($owing, 600_000, AccountDirection::Debit);
        // دفعة مقدمة — رصيد سالب في دفتر الأطراف
        $this->entry($prepaid, -150_000, AccountDirection::Credit);

        $split = app(PartyReclassificationService::class)->customers();

        $this->assertEqualsWithDelta(600_000.0, $split['debit'], 0.01);
        $this->assertEqualsWithDelta(150_000.0, $split['credit'], 0.01);
        $this->assertEqualsWithDelta(450_000.0, $split['net'], 0.01);
        $this->assertCount(1, $split['debit_parties']);
        $this->assertCount(1, $split['credit_parties']);
    }

    public function test_suppliers_split_the_other_way_because_credit_is_their_natural_side(): void
    {
        $owed = Supplier::factory()->create(['name' => 'مورد دائن']);
        $advanced = Supplier::factory()->create(['name' => 'مورد مدين']);

        // A supplier's balance is stored credit-positive: we still owe them.
        $this->entry($owed, 400_000, AccountDirection::Credit);
        // A negative balance is an advance we paid — an asset.
        $this->entry($advanced, -90_000, AccountDirection::Debit);

        $split = app(PartyReclassificationService::class)->suppliers();

        $this->assertEqualsWithDelta(90_000.0, $split['debit'], 0.01);
        $this->assertEqualsWithDelta(400_000.0, $split['credit'], 0.01);
        $this->assertEqualsWithDelta(-310_000.0, $split['net'], 0.01);
    }

    public function test_the_split_respects_the_as_of_date(): void
    {
        $customer = Customer::factory()->create();

        $this->entry($customer, 100_000, AccountDirection::Debit, '2026-03-01');
        $this->entry($customer, 250_000, AccountDirection::Debit, '2026-09-01');

        $split = app(PartyReclassificationService::class)->customers(Carbon::parse('2026-06-30'));

        $this->assertEqualsWithDelta(100_000.0, $split['debit'], 0.01);
    }

    public function test_balance_sheet_puts_debit_customers_in_assets_and_credit_customers_in_liabilities(): void
    {
        $control = Account::factory()->create([
            'code' => '1200',
            'name' => 'العملاء',
            'type' => AccountType::Asset,
            'nature' => AccountDirection::Debit,
            'statement_section' => StatementSection::CurrentAssets,
            'party_control' => 'customer',
            'opening_balance' => 450_000, // matches the sub-ledger net exactly
            'currency' => 'EGP',
        ]);

        $this->entry(Customer::factory()->create(), 600_000, AccountDirection::Debit);
        $this->entry(Customer::factory()->create(), -150_000, AccountDirection::Credit);

        $sheet = app(BalanceSheetService::class)->build(Carbon::parse('2026-12-31'), Carbon::parse('2026-01-01'));

        $assetSplit = collect($sheet['current_assets']['rows'])->firstWhere('kind', 'party_split');
        $liabilitySplit = collect($sheet['current_liabilities']['rows'])->firstWhere('kind', 'party_split');

        $this->assertEqualsWithDelta(600_000.0, $assetSplit['amount'], 0.01);
        $this->assertEqualsWithDelta(150_000.0, $liabilitySplit['amount'], 0.01);

        // The control account row itself is gone — replaced, not duplicated.
        $this->assertNull(
            collect($sheet['current_assets']['rows'])
                ->first(fn (array $r): bool => $r['kind'] === 'account' && ($r['account']?->id === $control->id))
        );

        // No variance, so no reconciliation row.
        $this->assertNull(collect($sheet['current_assets']['rows'])->firstWhere('kind', 'party_reconciliation'));
    }

    public function test_a_sub_ledger_that_does_not_explain_the_control_account_shows_a_reconciliation_row(): void
    {
        Account::factory()->create([
            'code' => '1200',
            'name' => 'العملاء',
            'type' => AccountType::Asset,
            'nature' => AccountDirection::Debit,
            'statement_section' => StatementSection::CurrentAssets,
            'party_control' => 'customer',
            'opening_balance' => 500_000, // 50,000 more than the sub-ledger explains
            'currency' => 'EGP',
        ]);

        $this->entry(Customer::factory()->create(), 450_000, AccountDirection::Debit);

        $sheet = app(BalanceSheetService::class)->build(Carbon::parse('2026-12-31'), Carbon::parse('2026-01-01'));

        $variance = collect($sheet['current_assets']['rows'])->firstWhere('kind', 'party_reconciliation');

        $this->assertNotNull($variance, 'The unexplained 50,000 must be surfaced, not hidden.');
        $this->assertEqualsWithDelta(50_000.0, $variance['amount'], 0.01);
    }

    public function test_splitting_never_changes_working_capital(): void
    {
        // The whole point: the split is presentation. Whatever it does to the
        // rows, the equations must land exactly where the control account's own
        // balance would have put them.
        Account::factory()->create([
            'code' => '1200',
            'type' => AccountType::Asset,
            'nature' => AccountDirection::Debit,
            'statement_section' => StatementSection::CurrentAssets,
            'party_control' => 'customer',
            'opening_balance' => 300_000,
            'currency' => 'EGP',
        ]);
        Account::factory()->create([
            'code' => '2010',
            'type' => AccountType::Liability,
            'nature' => AccountDirection::Credit,
            'statement_section' => StatementSection::CurrentLiabilities,
            'party_control' => 'supplier',
            'opening_balance' => 200_000,
            'currency' => 'EGP',
        ]);

        $this->entry(Customer::factory()->create(), 500_000, AccountDirection::Debit);
        $this->entry(Customer::factory()->create(), -200_000, AccountDirection::Credit);
        $this->entry(Supplier::factory()->create(), 350_000, AccountDirection::Credit);
        $this->entry(Supplier::factory()->create(), -150_000, AccountDirection::Debit);

        $sheet = app(BalanceSheetService::class)->build(Carbon::parse('2026-12-31'), Carbon::parse('2026-01-01'));

        // 300,000 receivable − 200,000 payable, however the rows are arranged.
        $this->assertEqualsWithDelta(100_000.0, $sheet['working_capital'], 0.01);
    }

    public function test_two_supplier_control_accounts_do_not_double_count_the_sub_ledger(): void
    {
        foreach (['2010' => 'مورد محلي', '2011' => 'مورد خارجي'] as $code => $name) {
            Account::factory()->create([
                'code' => (string) $code,
                'name' => $name,
                'type' => AccountType::Liability,
                'nature' => AccountDirection::Credit,
                'statement_section' => StatementSection::CurrentLiabilities,
                'party_control' => 'supplier',
                'opening_balance' => 150_000,
                'currency' => 'EGP',
            ]);
        }

        $this->entry(Supplier::factory()->create(), 300_000, AccountDirection::Credit);

        $sheet = app(BalanceSheetService::class)->build(Carbon::parse('2026-12-31'), Carbon::parse('2026-01-01'));

        $splits = collect($sheet['current_liabilities']['rows'])->where('kind', 'party_split');

        $this->assertCount(1, $splits, 'One sub-ledger must produce one split row, whatever the number of control accounts.');
        $this->assertEqualsWithDelta(300_000.0, $splits->first()['amount'], 0.01);
        // 300,000 combined control balance, all of it a liability.
        $this->assertEqualsWithDelta(-300_000.0, $sheet['working_capital'], 0.01);
    }
}
