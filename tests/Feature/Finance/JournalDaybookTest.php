<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\JournalDaybookService;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * اليومية التحليلية (قائمة المواد سلايد 2): posted entries laid out one per
 * row across a debit/credit pair per account.
 */
class JournalDaybookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{0:Account,1:float}  $debit
     * @param  array{0:Account,1:float}  $credit
     */
    private function postEntry(array $debit, array $credit, string $date): JournalEntry
    {
        $entry = JournalEntry::factory()->create(['entry_date' => $date, 'currency' => 'EGP']);

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

        return $entry;
    }

    public function test_entries_are_spread_across_the_selected_account_columns(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create(['name' => 'الخزينة']);
        $operating = Account::factory()->ofType(AccountType::Expense)->create(['name' => 'م. تشغيل']);

        $this->postEntry([$operating, 2300], [$treasury, 2300], '2026-06-16');

        $daybook = app(JournalDaybookService::class)->build(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
            [$treasury->id, $operating->id],
            'EGP',
        );

        $this->assertCount(1, $daybook['rows']);
        $row = $daybook['rows']->first();

        $this->assertSame(2300.0, $row['cells'][$operating->id]['debit']);
        $this->assertSame(0.0, $row['cells'][$operating->id]['credit']);
        $this->assertSame(2300.0, $row['cells'][$treasury->id]['credit']);
        $this->assertSame(2300.0, $row['total_debit']);
        $this->assertSame(2300.0, $row['total_credit']);
    }

    public function test_row_totals_include_accounts_outside_the_selected_columns(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();
        $bank = Account::factory()->ofType(AccountType::Asset)->create();

        $this->postEntry([$bank, 500], [$treasury, 500], '2026-06-10');

        // Only the treasury is a column; the bank side still has to be counted.
        $daybook = app(JournalDaybookService::class)->build(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
            [$treasury->id],
            'EGP',
        );

        $row = $daybook['rows']->first();

        $this->assertArrayNotHasKey($bank->id, $row['cells']);
        $this->assertSame(500.0, $row['total_debit']);
        $this->assertSame(500.0, $row['total_credit']);
        $this->assertSame(500.0, $daybook['column_totals'][$treasury->id]['credit']);
    }

    public function test_draft_entries_and_entries_outside_the_period_are_excluded(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();
        $revenue = Account::factory()->ofType(AccountType::Revenue)->create();

        $this->postEntry([$treasury, 1000], [$revenue, 1000], '2026-05-31');

        // Draft: created but never posted.
        $draft = JournalEntry::factory()->create(['entry_date' => '2026-06-05']);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $draft->id,
            'account_id' => $treasury->id,
            'direction' => AccountDirection::Debit,
            'amount' => 999,
        ]);

        $daybook = app(JournalDaybookService::class)->build(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
            [$treasury->id],
            'EGP',
        );

        $this->assertTrue($daybook['rows']->isEmpty());
        $this->assertSame(0.0, $daybook['total_debit']);
    }

    public function test_page_renders_the_period_entries(): void
    {
        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);

        $treasury = Account::factory()->ofType(AccountType::Asset)->create(['name' => 'الخزينة']);
        $operating = Account::factory()->ofType(AccountType::Expense)->create(['name' => 'م. تشغيل']);
        $entry = $this->postEntry([$operating, 2300], [$treasury, 2300], '2026-06-16');

        $user = \App\Models\User::factory()->create();
        $user->assignRole('Finance');
        $this->actingAs($user);

        \Livewire\Livewire::test(\App\Filament\Pages\JournalDaybook::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->set('accountIds', [$treasury->id, $operating->id])
            ->assertOk()
            ->assertSee('الخزينة', false)
            ->assertSee((string) $entry->entry_serial)
            ->assertSee('2,300.00')
            // Resetting the picker falls back to the busiest accounts, so the
            // table keeps rendering rather than emptying out.
            ->call('clearAccounts')
            ->assertSet('accountIds', [])
            ->assertOk()
            ->assertSee('2,300.00');
    }

    public function test_columns_fall_back_to_the_busiest_accounts_and_are_capped(): void
    {
        $accounts = collect(range(1, 8))->map(fn () => Account::factory()->ofType(AccountType::Asset)->create());
        $counter = Account::factory()->ofType(AccountType::Revenue)->create();

        foreach ($accounts as $index => $account) {
            $this->postEntry([$account, ($index + 1) * 100], [$counter, ($index + 1) * 100], '2026-06-0' . ($index + 1));
        }

        $daybook = app(JournalDaybookService::class)->build(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
            [],
            'EGP',
        );

        $this->assertCount(JournalDaybookService::MAX_COLUMNS, $daybook['accounts']);
        // The counter account carries every credit, so it is the busiest.
        $this->assertTrue($daybook['accounts']->contains('id', $counter->id));
        $this->assertCount(9, $daybook['available_accounts']);
    }
}
