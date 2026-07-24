<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Filament\Pages\GeneralLedgerReport;
use App\Filament\Pages\JournalDaybook;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\JournalEntryService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * كشف حساب الخزينة الشهري (قائمة المواد سلايد 3) + RBAC of the two new
 * finance report pages.
 */
class GeneralLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    /**
     * @param  array{0:Account,1:float}  $debit
     * @param  array{0:Account,1:float}  $credit
     */
    private function postEntry(array $debit, array $credit, string $date): void
    {
        $entry = JournalEntry::factory()->create(['entry_date' => $date]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debit[0]->id,
            'direction' => AccountDirection::Debit,
            'amount' => $debit[1],
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $credit[0]->id,
            'direction' => AccountDirection::Credit,
            'amount' => $credit[1],
        ]);

        app(JournalEntryService::class)->post($entry->fresh('lines'));
    }

    private function financeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Finance');

        return $user;
    }

    public function test_statement_opens_with_prior_movement_and_carries_a_running_balance(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create(['opening_balance' => 1000]);
        $revenue = Account::factory()->ofType(AccountType::Revenue)->create();
        $expense = Account::factory()->ofType(AccountType::Expense)->create();

        // Before the period — folded into the opening balance.
        $this->postEntry([$treasury, 500], [$revenue, 500], '2026-05-20');
        // Inside the period.
        $this->postEntry([$expense, 300], [$treasury, 300], '2026-06-10');
        $this->postEntry([$treasury, 200], [$revenue, 200], '2026-06-20');

        $this->actingAs($this->financeUser());

        $page = Livewire::test(GeneralLedgerReport::class)
            ->set('accountId', $treasury->id)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30');

        $ledger = $page->instance()->getLedger();

        $this->assertSame(1500.0, $ledger['opening']);
        $this->assertCount(2, $ledger['rows']);
        $this->assertSame(1200.0, $ledger['rows'][0]['balance']);
        $this->assertSame(1400.0, $ledger['rows'][1]['balance']);
        $this->assertSame(['debit' => 200.0, 'credit' => 300.0], $ledger['totals']);
        $this->assertSame(1400.0, $ledger['closing']);
    }

    public function test_statement_rows_carry_the_entry_serial(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();
        $revenue = Account::factory()->ofType(AccountType::Revenue)->create();

        $this->postEntry([$treasury, 100], [$revenue, 100], '2026-06-02');

        $this->actingAs($this->financeUser());

        $ledger = Livewire::test(GeneralLedgerReport::class)
            ->set('accountId', $treasury->id)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->instance()
            ->getLedger();

        $this->assertNotNull($ledger['rows'][0]['entry_serial']);
    }

    public function test_printable_statement_and_daybook_render_as_pdf(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();
        $revenue = Account::factory()->ofType(AccountType::Revenue)->create();
        $this->postEntry([$treasury, 100], [$revenue, 100], '2026-06-02');

        $this->actingAs($this->financeUser());

        $this->get(route('finance.general_ledger.pdf', [
            'account' => $treasury->id,
            'from' => '2026-06-01',
            'to' => '2026-06-30',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('finance.daybook.pdf', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'currency' => 'EGP',
            'accounts' => $treasury->id,
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_printable_reports_are_gated_by_permission(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();

        $outsider = User::factory()->create();
        $outsider->assignRole('Sales');
        $this->actingAs($outsider);

        $this->get(route('finance.general_ledger.pdf', ['account' => $treasury->id]))->assertForbidden();
        $this->get(route('finance.daybook.pdf'))->assertForbidden();
    }

    public function test_report_pages_are_gated_by_permission(): void
    {
        $this->actingAs($this->financeUser());
        $this->assertTrue(GeneralLedgerReport::canAccess());
        $this->assertTrue(JournalDaybook::canAccess());

        $outsider = User::factory()->create();
        $outsider->assignRole('Sales');
        $this->actingAs($outsider);

        $this->assertFalse(GeneralLedgerReport::canAccess());
        $this->assertFalse(JournalDaybook::canAccess());
    }
}
