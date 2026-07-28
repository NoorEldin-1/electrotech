<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Filament\Resources\JournalEntryResource\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntryResource\Pages\EditJournalEntry;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\JournalEntryService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The fast-entry journal form: the document type arrives from the list page's
 * dropdown, the treasury lands on the side that type implies, and each side is
 * written in its own column.
 */
class JournalEntryFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
    }

    private function treasury(): Account
    {
        return Account::factory()->create(['code' => '1010', 'name' => 'الخزينة', 'is_active' => true]);
    }

    public function test_create_page_preselects_the_document_type_from_the_url(): void
    {
        $this->treasury();

        Livewire::withQueryParams(['document_type' => DocumentType::Settlement->value])
            ->test(CreateJournalEntry::class)
            ->assertFormSet(['document_type' => DocumentType::Settlement->value]);
    }

    public function test_payment_order_opens_with_the_treasury_on_the_credit_side(): void
    {
        $treasury = $this->treasury();

        $state = Livewire::withQueryParams(['document_type' => DocumentType::PaymentOrder->value])
            ->test(CreateJournalEntry::class)
            ->assertFormSet(['document_type' => DocumentType::PaymentOrder->value])
            ->get('data');

        $this->assertSame($treasury->id, array_values($state['credit_lines'])[0]['account_id']);
        $this->assertNull(array_values($state['debit_lines'])[0]['account_id'] ?? null);
    }

    public function test_supply_receipt_opens_with_the_treasury_on_the_debit_side(): void
    {
        $treasury = $this->treasury();

        $state = Livewire::withQueryParams(['document_type' => DocumentType::SupplyReceipt->value])
            ->test(CreateJournalEntry::class)
            ->get('data');

        $this->assertSame($treasury->id, array_values($state['debit_lines'])[0]['account_id']);
        $this->assertNull(array_values($state['credit_lines'])[0]['account_id'] ?? null);
    }

    public function test_settlement_and_unknown_types_fill_nothing(): void
    {
        $this->treasury();

        foreach ([DocumentType::Settlement->value, 'not-a-type'] as $type) {
            $state = Livewire::withQueryParams(['document_type' => $type])
                ->test(CreateJournalEntry::class)
                ->get('data');

            $this->assertNull(array_values($state['debit_lines'])[0]['account_id'] ?? null);
            $this->assertNull(array_values($state['credit_lines'])[0]['account_id'] ?? null);
        }
    }

    public function test_changing_the_document_type_moves_the_treasury_to_the_other_side(): void
    {
        $treasury = $this->treasury();

        $state = Livewire::withQueryParams(['document_type' => DocumentType::PaymentOrder->value])
            ->test(CreateJournalEntry::class)
            ->set('data.document_type', DocumentType::SupplyReceipt->value)
            ->get('data');

        $this->assertSame($treasury->id, array_values($state['debit_lines'])[0]['account_id']);
        $this->assertNull(array_values($state['credit_lines'])[0]['account_id']);
    }

    public function test_the_lines_section_renders_as_two_named_columns(): void
    {
        $html = Livewire::test(CreateJournalEntry::class)
            ->assertSee(__('resources.journal_entries.sections.debit_lines'))
            ->assertSee(__('resources.journal_entries.sections.credit_lines'))
            ->assertSee(__('resources.journal_entries.actions.add_debit_line'))
            ->assertSee(__('resources.journal_entries.actions.add_credit_line'))
            ->assertSee(__('resources.journal_entries.fields.show_line_details'))
            ->html();

        $this->assertSame(2, substr_count($html, 'et-journal-lines'));

        // The per-line direction picker is gone: the side a line sits on is
        // what makes it debit or credit now.
        $this->assertStringNotContainsString('.direction', $html);
    }

    public function test_balance_bar_reports_the_totals_and_the_balanced_state(): void
    {
        $a = Account::factory()->create(['is_active' => true]);
        $b = Account::factory()->create(['is_active' => true]);

        $page = Livewire::test(CreateJournalEntry::class)
            ->assertSee(__('resources.journal_entries.placeholders.total_debit'))
            ->set('data.debit_lines', [['account_id' => $a->id, 'amount' => 1000]])
            ->set('data.credit_lines', [['account_id' => $b->id, 'amount' => 400]]);

        $page->assertSee(__('resources.journal_entries.placeholders.difference'))
            ->assertDontSee(__('resources.journal_entries.placeholders.balanced'));

        $creditKey = array_key_first($page->get('data')['credit_lines']);

        $page->set("data.credit_lines.{$creditKey}.amount", 1000)
            ->assertSee(__('resources.journal_entries.placeholders.balanced'));
    }

    public function test_a_new_line_opens_with_the_remaining_difference(): void
    {
        $expense = Account::factory()->create(['is_active' => true]);

        $page = Livewire::test(CreateJournalEntry::class)
            ->set('data.debit_lines', [['account_id' => $expense->id, 'amount' => 12000]])
            ->set('data.credit_lines', [])
            ->call('mountFormComponentAction', 'data.credit_lines', 'add');

        $lines = array_values($page->get('data')['credit_lines']);

        $this->assertCount(1, $lines);
        $this->assertEquals(12000, (float) $lines[0]['amount']);
    }

    public function test_the_calculator_button_recomputes_the_line_from_the_other_side(): void
    {
        $a = Account::factory()->create(['is_active' => true]);
        $b = Account::factory()->create(['is_active' => true]);

        $page = Livewire::test(CreateJournalEntry::class)
            ->set('data.debit_lines', [['account_id' => $a->id, 'amount' => 5000]])
            ->set('data.credit_lines', [['account_id' => $b->id, 'amount' => 999]]);

        $creditKey = array_key_first($page->get('data')['credit_lines']);

        $page->call('mountFormComponentAction', "data.credit_lines.{$creditKey}.amount", 'fillRemaining');

        $this->assertEquals(5000, (float) $page->get('data')['credit_lines'][$creditKey]['amount']);
    }

    public function test_creating_an_entry_writes_both_sides_with_the_right_direction(): void
    {
        $expense = Account::factory()->create(['code' => '5030', 'is_active' => true]);
        $treasury = $this->treasury();

        Livewire::test(CreateJournalEntry::class)
            ->fillForm([
                'document_type' => DocumentType::PaymentOrder->value,
                'entry_date' => '2026-07-28',
                'description' => 'فاتورة كهربا',
                'currency' => 'EGP',
            ])
            ->set('data.debit_lines', [['account_id' => $expense->id, 'amount' => 12000]])
            ->set('data.credit_lines', [['account_id' => $treasury->id, 'amount' => 12000]])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::query()->latest('id')->with('lines')->first();

        $this->assertCount(2, $entry->lines);
        $this->assertSame(AccountDirection::Debit, $entry->lines->firstWhere('account_id', $expense->id)->direction);
        $this->assertSame(AccountDirection::Credit, $entry->lines->firstWhere('account_id', $treasury->id)->direction);
        $this->assertEquals(12000, (float) $entry->total_debit);
        $this->assertEquals(12000, (float) $entry->total_credit);
        $this->assertStringStartsWith('PV-', $entry->entry_number);
    }

    public function test_creating_a_multi_party_entry(): void
    {
        [$a, $b, $c] = [
            Account::factory()->create(['is_active' => true]),
            Account::factory()->create(['is_active' => true]),
            Account::factory()->create(['is_active' => true]),
        ];

        Livewire::test(CreateJournalEntry::class)
            ->fillForm([
                'document_type' => DocumentType::Settlement->value,
                'entry_date' => '2026-07-28',
                'currency' => 'EGP',
            ])
            ->set('data.debit_lines', [
                ['account_id' => $a->id, 'amount' => 2300],
                ['account_id' => $b->id, 'amount' => 20300],
            ])
            ->set('data.credit_lines', [['account_id' => $c->id, 'amount' => 22600]])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::query()->latest('id')->with('lines')->first();

        $this->assertCount(3, $entry->lines);
        $this->assertEquals(22600, (float) $entry->total_debit);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_edit_page_splits_the_lines_and_saves_them_back(): void
    {
        $entry = JournalEntry::factory()->create(['currency' => 'EGP']);
        [$a, $b, $c] = [
            Account::factory()->create(['is_active' => true]),
            Account::factory()->create(['is_active' => true]),
            Account::factory()->create(['is_active' => true]),
        ];

        app(JournalEntryService::class)->syncLines(
            $entry,
            [['account_id' => $a->id, 'amount' => 500]],
            [['account_id' => $b->id, 'amount' => 500]],
        );

        $page = Livewire::test(EditJournalEntry::class, ['record' => $entry->getKey()]);

        $data = $page->get('data');
        $this->assertCount(1, $data['debit_lines']);
        $this->assertSame($a->id, array_values($data['debit_lines'])[0]['account_id']);
        $this->assertCount(1, $data['credit_lines']);

        // Raise the debit, and split the credit over two accounts.
        $debitKey = array_key_first($data['debit_lines']);
        $creditKey = array_key_first($data['credit_lines']);

        $page->set("data.debit_lines.{$debitKey}.amount", 900)
            ->set("data.credit_lines.{$creditKey}.amount", 400)
            ->set('data.credit_lines.new', ['id' => null, 'account_id' => $c->id, 'amount' => 500])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry->refresh()->load('lines');

        $this->assertCount(3, $entry->lines);
        $this->assertEquals(900, (float) $entry->total_debit);
        $this->assertEquals(900, (float) $entry->total_credit);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_hidden_cost_center_survives_a_save(): void
    {
        $entry = JournalEntry::factory()->create();
        $project = Project::factory()->create();
        $expense = Account::factory()->create(['is_active' => true]);
        $treasury = $this->treasury();

        app(JournalEntryService::class)->syncLines(
            $entry,
            [['account_id' => $expense->id, 'project_id' => $project->id, 'amount' => 700]],
            [['account_id' => $treasury->id, 'amount' => 700]],
        );

        // The details toggle stays off, so project_id is never rendered — it
        // must still be written back untouched.
        Livewire::test(EditJournalEntry::class, ['record' => $entry->getKey()])
            ->set('data.description', 'مصروف على العملية')
            ->call('save')
            ->assertHasNoFormErrors();

        $entry->refresh()->load('lines');

        $this->assertSame($project->id, $entry->lines->firstWhere('account_id', $expense->id)->project_id);
    }
}
