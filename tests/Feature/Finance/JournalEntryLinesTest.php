<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The debit/credit split the journal form writes through: مدين on one side,
 * دائن on the other, both landing in the same `journal_entry_lines` table.
 */
class JournalEntryLinesTest extends TestCase
{
    use RefreshDatabase;

    private function service(): JournalEntryService
    {
        return app(JournalEntryService::class);
    }

    public function test_split_lines_groups_each_direction_and_keeps_line_data(): void
    {
        $entry = JournalEntry::factory()->create();
        $expense = Account::factory()->create();
        $treasury = Account::factory()->create();
        $project = Project::factory()->create();

        $debit = JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expense->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 1200,
            'line_notes' => 'فاتورة كهربا',
        ]);

        $credit = JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $treasury->id,
            'direction' => AccountDirection::Credit,
            'amount' => 1200,
        ]);

        $split = JournalEntryService::splitLines($entry->fresh('lines'));

        $this->assertCount(1, $split['debit_lines']);
        $this->assertCount(1, $split['credit_lines']);

        $this->assertSame($debit->id, $split['debit_lines'][0]['id']);
        $this->assertSame($expense->id, $split['debit_lines'][0]['account_id']);
        $this->assertSame($project->id, $split['debit_lines'][0]['project_id']);
        $this->assertEquals(1200, (float) $split['debit_lines'][0]['amount']);
        $this->assertSame('فاتورة كهربا', $split['debit_lines'][0]['line_notes']);

        $this->assertSame($credit->id, $split['credit_lines'][0]['id']);
        $this->assertNull($split['credit_lines'][0]['project_id']);
    }

    public function test_sync_lines_creates_both_sides_with_the_right_direction(): void
    {
        $entry = JournalEntry::factory()->create();
        $expense = Account::factory()->create();
        $treasury = Account::factory()->create();

        $this->service()->syncLines(
            $entry,
            [['account_id' => $expense->id, 'amount' => 12000]],
            [['account_id' => $treasury->id, 'amount' => 12000]],
        );

        $entry->refresh()->load('lines');

        $this->assertCount(2, $entry->lines);
        $this->assertSame(AccountDirection::Debit, $entry->lines->firstWhere('account_id', $expense->id)->direction);
        $this->assertSame(AccountDirection::Credit, $entry->lines->firstWhere('account_id', $treasury->id)->direction);
        $this->assertEquals(12000, (float) $entry->total_debit);
        $this->assertEquals(12000, (float) $entry->total_credit);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_sync_lines_supports_multi_party_entries(): void
    {
        $entry = JournalEntry::factory()->create();
        [$a, $b, $c] = [Account::factory()->create(), Account::factory()->create(), Account::factory()->create()];

        $this->service()->syncLines(
            $entry,
            [
                ['account_id' => $a->id, 'amount' => 2300],
                ['account_id' => $b->id, 'amount' => 20300],
            ],
            [['account_id' => $c->id, 'amount' => 22600]],
        );

        $entry->refresh()->load('lines');

        $this->assertCount(3, $entry->lines);
        $this->assertEquals(22600, (float) $entry->total_debit);
        $this->assertEquals(22600, (float) $entry->total_credit);
    }

    public function test_sync_lines_updates_edits_and_deletes_removed_rows_in_one_save(): void
    {
        $entry = JournalEntry::factory()->create();
        [$a, $b, $c] = [Account::factory()->create(), Account::factory()->create(), Account::factory()->create()];

        $this->service()->syncLines(
            $entry,
            [['account_id' => $a->id, 'amount' => 500]],
            [
                ['account_id' => $b->id, 'amount' => 300],
                ['account_id' => $c->id, 'amount' => 200],
            ],
        );

        $entry->refresh()->load('lines');
        $keptDebit = $entry->lines->firstWhere('account_id', $a->id);
        $keptCredit = $entry->lines->firstWhere('account_id', $b->id);

        // Edit the debit amount, keep one credit row, drop the other, add a new
        // debit row — all in a single save.
        $this->service()->syncLines(
            $entry,
            [
                ['id' => $keptDebit->id, 'account_id' => $a->id, 'amount' => 700],
                ['account_id' => $c->id, 'amount' => 100],
            ],
            [['id' => $keptCredit->id, 'account_id' => $b->id, 'amount' => 800]],
        );

        $entry->refresh()->load('lines');

        $this->assertCount(3, $entry->lines);
        $this->assertSame($keptDebit->id, $entry->lines->firstWhere('account_id', $a->id)->id);
        $this->assertEquals(700, (float) $entry->lines->firstWhere('account_id', $a->id)->amount);
        $this->assertDatabaseCount('journal_entry_lines', 3);
        $this->assertEquals(800, (float) $entry->total_debit);
        $this->assertEquals(800, (float) $entry->total_credit);
    }

    public function test_a_cloned_row_becomes_a_new_line_instead_of_overwriting_its_source(): void
    {
        $entry = JournalEntry::factory()->create();
        $expense = Account::factory()->create();
        $treasury = Account::factory()->create();

        $this->service()->syncLines(
            $entry,
            [['account_id' => $expense->id, 'amount' => 400]],
            [['account_id' => $treasury->id, 'amount' => 400]],
        );

        $source = $entry->refresh()->load('lines')->lines->firstWhere('account_id', $expense->id);

        // The clone button copies the row as it stands — id included.
        $this->service()->syncLines(
            $entry,
            [
                ['id' => $source->id, 'account_id' => $expense->id, 'amount' => 400],
                ['id' => $source->id, 'account_id' => $expense->id, 'amount' => 400],
            ],
            [['account_id' => $treasury->id, 'amount' => 800]],
        );

        $entry->refresh()->load('lines');

        $this->assertCount(3, $entry->lines);
        $this->assertEquals(800, (float) $entry->total_debit);
        $this->assertEquals(800, (float) $entry->total_credit);
    }

    public function test_sync_lines_ignores_rows_without_an_account(): void
    {
        $entry = JournalEntry::factory()->create();
        $account = Account::factory()->create();

        $this->service()->syncLines(
            $entry,
            [['account_id' => $account->id, 'amount' => 100], ['account_id' => null, 'amount' => null]],
            [],
        );

        $this->assertCount(1, $entry->refresh()->load('lines')->lines);
    }

    public function test_sync_lines_refuses_a_posted_entry(): void
    {
        $entry = JournalEntry::factory()->posted()->create();
        $account = Account::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service()->syncLines($entry, [['account_id' => $account->id, 'amount' => 100]], []);
    }

    public function test_payment_order_puts_the_treasury_on_the_credit_side(): void
    {
        $treasury = Account::factory()->create(['code' => '1010', 'is_active' => true]);

        $resolved = JournalEntryService::treasuryAccountFor(DocumentType::PaymentOrder, 'EGP');

        $this->assertSame(AccountDirection::Credit, $resolved['direction']);
        $this->assertSame('credit_lines', $resolved['side']);
        $this->assertSame($treasury->id, $resolved['account_id']);
    }

    public function test_supply_receipt_puts_the_treasury_on_the_debit_side(): void
    {
        $treasury = Account::factory()->create(['code' => '1010', 'is_active' => true]);

        $resolved = JournalEntryService::treasuryAccountFor(DocumentType::SupplyReceipt, 'EGP');

        $this->assertSame(AccountDirection::Debit, $resolved['direction']);
        $this->assertSame('debit_lines', $resolved['side']);
        $this->assertSame($treasury->id, $resolved['account_id']);
    }

    public function test_settlement_has_no_treasury_side(): void
    {
        Account::factory()->create(['code' => '1010', 'is_active' => true]);

        $this->assertNull(JournalEntryService::treasuryAccountFor(DocumentType::Settlement, 'EGP'));
        $this->assertNull(JournalEntryService::treasuryAccountFor(null, 'EGP'));
        $this->assertNull(JournalEntryService::treasuryAccountFor('not-a-type', 'EGP'));
    }

    public function test_foreign_currency_uses_its_own_treasury_account(): void
    {
        Account::factory()->create(['code' => '1010', 'is_active' => true]);
        $foreign = Account::factory()->create(['code' => '1011', 'currency' => 'USD', 'is_active' => true]);

        $resolved = JournalEntryService::treasuryAccountFor(DocumentType::PaymentOrder, 'USD');

        $this->assertSame($foreign->id, $resolved['account_id']);
    }

    public function test_missing_or_inactive_treasury_account_resolves_to_null(): void
    {
        $this->assertNull(JournalEntryService::treasuryAccountFor(DocumentType::PaymentOrder, 'EGP'));

        Account::factory()->create(['code' => '1010', 'is_active' => false]);

        $this->assertNull(JournalEntryService::treasuryAccountFor(DocumentType::PaymentOrder, 'EGP'));
    }
}
