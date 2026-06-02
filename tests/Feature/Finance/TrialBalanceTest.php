<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\JournalEntryService;
use App\Services\TrialBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function postEntry(Account $debit, Account $credit, float $amount, string $date): void
    {
        $entry = JournalEntry::factory()->create(['entry_date' => $date]);

        JournalEntryLine::factory()->create(['journal_entry_id' => $entry->id, 'account_id' => $debit->id, 'direction' => AccountDirection::Debit, 'amount' => $amount]);
        JournalEntryLine::factory()->create(['journal_entry_id' => $entry->id, 'account_id' => $credit->id, 'direction' => AccountDirection::Credit, 'amount' => $amount]);

        app(JournalEntryService::class)->post($entry->fresh('lines'));
    }

    public function test_trial_balance_matches_spec_example(): void
    {
        // Mirrors سلايد 4: treasury 2000/1000/1000, bank 5000/4500/500.
        $treasury = Account::factory()->ofType(AccountType::Asset)->create(['code' => 'T', 'name' => 'الخزينة', 'currency' => 'EGP']);
        $bank = Account::factory()->ofType(AccountType::Asset)->create(['code' => 'B', 'name' => 'بنك التجاري', 'currency' => 'EGP']);
        $rev = Account::factory()->ofType(AccountType::Revenue)->create(['code' => 'R', 'currency' => 'EGP']);
        $exp = Account::factory()->ofType(AccountType::Expense)->create(['code' => 'E', 'currency' => 'EGP']);

        $this->postEntry($treasury, $rev, 2000, '2026-01-01');
        $this->postEntry($exp, $treasury, 1000, '2026-01-02');
        $this->postEntry($bank, $rev, 5000, '2026-01-03');
        $this->postEntry($exp, $bank, 4500, '2026-01-04');

        $group = app(TrialBalanceService::class)->grouped()->get('EGP');
        $rows = $group['rows']->keyBy(fn (array $r) => $r['account']->code);

        $this->assertEquals(2000.0, $rows['T']['debit']);
        $this->assertEquals(1000.0, $rows['T']['credit']);
        $this->assertEquals(1000.0, $rows['T']['balance']);

        $this->assertEquals(5000.0, $rows['B']['debit']);
        $this->assertEquals(4500.0, $rows['B']['credit']);
        $this->assertEquals(500.0, $rows['B']['balance']);

        $this->assertTrue($group['balanced']);
        $this->assertEquals($group['total_debit'], $group['total_credit']);
    }

    public function test_currencies_are_grouped_separately(): void
    {
        $egpA = Account::factory()->ofType(AccountType::Asset)->create(['currency' => 'EGP']);
        $egpB = Account::factory()->ofType(AccountType::Revenue)->create(['currency' => 'EGP']);
        $usdA = Account::factory()->ofType(AccountType::Asset)->create(['currency' => 'USD']);
        $usdB = Account::factory()->ofType(AccountType::Revenue)->create(['currency' => 'USD']);

        $this->postEntry($egpA, $egpB, 1000, '2026-01-01');
        $this->postEntry($usdA, $usdB, 700, '2026-01-01');

        $grouped = app(TrialBalanceService::class)->grouped();

        $this->assertTrue($grouped->has('EGP'));
        $this->assertTrue($grouped->has('USD'));
        $this->assertEquals(1000.0, $grouped->get('EGP')['total_debit']);
        $this->assertEquals(700.0, $grouped->get('USD')['total_debit']);
    }

    public function test_draft_entries_do_not_affect_trial_balance(): void
    {
        $a = Account::factory()->ofType(AccountType::Asset)->create(['currency' => 'EGP']);
        $b = Account::factory()->ofType(AccountType::Revenue)->create(['currency' => 'EGP']);

        // Draft only — never posted.
        $draft = JournalEntry::factory()->create(['entry_date' => '2026-01-01']);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $a->id, 'direction' => AccountDirection::Debit, 'amount' => 1000]);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $b->id, 'direction' => AccountDirection::Credit, 'amount' => 1000]);

        $this->assertTrue(app(TrialBalanceService::class)->grouped()->isEmpty());
    }

    public function test_as_of_date_filters_movements(): void
    {
        $a = Account::factory()->ofType(AccountType::Asset)->create(['code' => 'A', 'currency' => 'EGP']);
        $b = Account::factory()->ofType(AccountType::Revenue)->create(['code' => 'B', 'currency' => 'EGP']);

        $this->postEntry($a, $b, 1000, '2026-01-01');
        $this->postEntry($a, $b, 500, '2026-03-01');

        $group = app(TrialBalanceService::class)->grouped(\Illuminate\Support\Carbon::parse('2026-02-01'));
        $rows = $group->get('EGP')['rows']->keyBy(fn (array $r) => $r['account']->code);

        // Only the January movement counts as of 2026-02-01.
        $this->assertEquals(1000.0, $rows['A']['debit']);
        $this->assertEquals(1000.0, $rows['A']['balance']);
    }
}
