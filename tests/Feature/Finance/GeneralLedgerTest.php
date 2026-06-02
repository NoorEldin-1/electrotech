<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\GeneralLedgerService;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create and post a two-line balanced entry.
     *
     * @param  array{0:Account,1:float}  $debit
     * @param  array{0:Account,1:float}  $credit
     */
    private function postEntry(array $debit, array $credit, string $date): JournalEntry
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

        return $entry;
    }

    public function test_ledger_running_balance_for_debit_account(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();
        $other = Account::factory()->ofType(AccountType::Revenue)->create();
        $expense = Account::factory()->ofType(AccountType::Expense)->create();

        $this->postEntry([$treasury, 2000], [$other, 2000], '2026-01-01');
        $this->postEntry([$expense, 1000], [$treasury, 1000], '2026-01-05');

        $ledger = app(GeneralLedgerService::class)->for($treasury);

        $this->assertCount(2, $ledger);
        $this->assertEquals(2000.0, $ledger[0]['debit']);
        $this->assertEquals(2000.0, $ledger[0]['balance']);
        $this->assertEquals(1000.0, $ledger[1]['credit']);
        $this->assertEquals(1000.0, $ledger[1]['balance']);

        $this->assertEquals(1000.0, app(GeneralLedgerService::class)->closingBalance($treasury));
        $this->assertEquals(['debit' => 2000.0, 'credit' => 1000.0], app(GeneralLedgerService::class)->totals($treasury));
    }

    public function test_ledger_running_balance_for_credit_account(): void
    {
        $sales = Account::factory()->ofType(AccountType::Revenue)->create();
        $cash = Account::factory()->ofType(AccountType::Asset)->create();

        $this->postEntry([$cash, 2000], [$sales, 2000], '2026-01-01');

        $ledger = app(GeneralLedgerService::class)->for($sales);

        // Revenue is credit-natured: a credit increases its balance.
        $this->assertEquals(2000.0, $ledger[0]['credit']);
        $this->assertEquals(2000.0, $ledger[0]['balance']);
    }

    public function test_opening_balance_is_respected(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->withOpeningBalance(500)->create();
        $other = Account::factory()->ofType(AccountType::Revenue)->create();

        $this->postEntry([$treasury, 1000], [$other, 1000], '2026-02-01');

        $ledger = app(GeneralLedgerService::class)->for($treasury);

        // Opening 500 + debit 1000 = 1500.
        $this->assertEquals(1500.0, $ledger[0]['balance']);
        $this->assertEquals(1500.0, app(GeneralLedgerService::class)->closingBalance($treasury));
    }

    public function test_draft_entries_are_excluded(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();
        $other = Account::factory()->ofType(AccountType::Revenue)->create();

        $this->postEntry([$treasury, 1000], [$other, 1000], '2026-01-01');

        // A draft entry that is never posted.
        $draft = JournalEntry::factory()->create(['entry_date' => '2026-01-02']);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $treasury->id, 'direction' => AccountDirection::Debit, 'amount' => 9999]);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $other->id, 'direction' => AccountDirection::Credit, 'amount' => 9999]);

        $ledger = app(GeneralLedgerService::class)->for($treasury);

        $this->assertCount(1, $ledger);
        $this->assertEquals(1000.0, app(GeneralLedgerService::class)->closingBalance($treasury));
    }

    public function test_date_filter_sets_opening_from_prior_movement(): void
    {
        $treasury = Account::factory()->ofType(AccountType::Asset)->create();
        $other = Account::factory()->ofType(AccountType::Revenue)->create();

        $this->postEntry([$treasury, 1000], [$other, 1000], '2026-01-01');
        $this->postEntry([$treasury, 500], [$other, 500], '2026-02-10');

        $ledger = app(GeneralLedgerService::class)->for($treasury, Carbon::parse('2026-02-01'));

        // Only the February movement shows; January (1000) folds into opening.
        $this->assertCount(1, $ledger);
        $this->assertEquals(1500.0, $ledger[0]['balance']);
    }
}
