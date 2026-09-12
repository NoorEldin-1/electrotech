<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Finance;

use App\Enums\AccountType;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * دليل الحسابات وقيود اليومية — the chart of accounts and the journal.
 *
 * The rule under test throughout is that a POSTED entry is immutable and that
 * nothing reaches the ledger unbalanced.
 */
class LedgerApiTest extends ApiTestCase
{
    // ---------------------------------------------------- chart of accounts

    public function test_it_creates_an_account_and_publishes_its_natural_sign(): void
    {
        $response = $this->actingAsApi($this->userWith(['accounts.create']))
            ->apiPost(self::BASE.'/accounts', [
                'code' => '5070',
                'name' => 'تكلفة المبيعات',
                'type' => AccountType::Expense->value,
                'statement_section' => 'cost_of_sales',
            ]);

        $response->assertCreated();
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.account_type.value', 'expense');

        // Published so a client can turn (debit − credit) into a balance the
        // right way up instead of guessing per account type.
        $response->assertJsonPath('data.natural_sign', 1);

        $response->assertJsonPath('data.is_active', true);
    }

    public function test_an_account_code_must_be_unique(): void
    {
        Account::factory()->create(['code' => '5070']);

        $response = $this->actingAsApi($this->userWith(['accounts.create']))
            ->apiPost(self::BASE.'/accounts', [
                'code' => '5070',
                'name' => 'Duplicate',
                'type' => AccountType::Expense->value,
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['code']]]);
    }

    public function test_the_detail_endpoint_carries_a_balance_and_the_list_does_not(): void
    {
        $account = Account::factory()->create(['opening_balance' => 1000]);

        $this->actingAsApi($this->userWith(['accounts.view']))
            ->apiGet(self::BASE.'/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.balance', '1000.00');

        // Working out a balance means walking the account's posted lines, so a
        // page of two hundred accounts must not pay for two hundred walks.
        $this->actingAsApi($this->userWith(['accounts.view']))
            ->apiGet(self::BASE.'/accounts')
            ->assertOk()
            ->assertJsonMissingPath('data.0.balance');
    }

    public function test_an_account_carrying_journal_lines_cannot_be_deleted(): void
    {
        $entry = $this->draftEntry();
        $account = Account::factory()->create();
        $entry->lines()->create([
            'account_id' => $account->id,
            'direction' => \App\Enums\AccountDirection::Debit,
            'amount' => 100,
        ]);

        $response = $this->actingAsApi($this->userWith(['accounts.delete']))
            ->apiDelete(self::BASE.'/accounts/'.$account->id);

        // A deleted account would leave posted lines pointing at nothing.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_an_account_with_children_cannot_be_deleted(): void
    {
        $parent = Account::factory()->create();
        Account::factory()->create(['parent_id' => $parent->id]);

        $response = $this->actingAsApi($this->userWith(['accounts.delete']))
            ->apiDelete(self::BASE.'/accounts/'.$parent->id);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    // -------------------------------------------------------- journal entries

    public function test_it_writes_an_entry_as_two_columns_and_posts_it(): void
    {
        $debit = Account::factory()->ofType(AccountType::Expense)->create();
        $credit = Account::factory()->ofType(AccountType::Asset)->create();

        $created = $this->actingAsApi($this->userWith(['journal_entries.create']))
            ->apiPost(self::BASE.'/journal-entries', [
                'document_type' => DocumentType::Settlement->value,
                'description' => 'Closing the cost centre',
                'debit_lines' => [['account_id' => $debit->id, 'amount' => 40000]],
                'credit_lines' => [['account_id' => $credit->id, 'amount' => 40000]],
            ]);

        $created->assertCreated();
        $created->assertJsonPath('data.status.value', 'draft');

        // All three numbers are the server's: the continuous serial an auditor
        // follows, the per-type document number, and the reference.
        $this->assertNotNull($created->json('data.entry_serial'));
        $this->assertNotNull($created->json('data.document_number'));
        $this->assertNotNull($created->json('data.entry_number'));

        // The side a line was written on IS its direction — there is no
        // per-row direction field to mistype.
        $created->assertJsonPath('data.debit_lines.0.direction.value', 'debit');
        $created->assertJsonPath('data.credit_lines.0.direction.value', 'credit');
        $created->assertJsonPath('data.totals.balanced', true);

        $id = $created->json('data.id');

        $this->actingAsApi($this->userWith(['journal_entries.post']))
            ->apiPost(self::BASE.'/journal-entries/'.$id.'/post')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'posted')
            ->assertJsonPath('data.totals.debit', '40000.00')
            ->assertJsonPath('data.totals.credit', '40000.00');
    }

    public function test_an_unbalanced_entry_cannot_be_posted(): void
    {
        $entry = $this->balancedDraft(debit: 40000, credit: 39000);

        $response = $this->actingAsApi($this->userWith(['journal_entries.post']))
            ->apiPost(self::BASE.'/journal-entries/'.$entry->id.'/post');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        // The message names both figures, so it can be shown as written.
        $this->assertStringContainsString('40,000.00', $response->json('error.message'));
        $this->assertSame(JournalStatus::Draft, $entry->fresh()->status);
    }

    public function test_a_one_sided_entry_cannot_be_posted(): void
    {
        $entry = $this->draftEntry();
        $entry->lines()->create([
            'account_id' => Account::factory()->create()->id,
            'direction' => \App\Enums\AccountDirection::Debit,
            'amount' => 100,
        ]);

        $response = $this->actingAsApi($this->userWith(['journal_entries.post']))
            ->apiPost(self::BASE.'/journal-entries/'.$entry->id.'/post');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_a_posted_entry_is_immutable(): void
    {
        $entry = $this->balancedDraft();
        app(\App\Services\JournalEntryService::class)->post($entry);

        $user = $this->userWith([
            'journal_entries.edit',
            'journal_entries.delete',
            'journal_entries.post',
        ]);

        // 403 rather than 422 across the board: JournalEntryPolicy gates
        // update, delete and post on isDraft(), so it answers before any
        // service guard. A client branching on error.code must expect
        // `forbidden` here.
        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/journal-entries/'.$entry->id, ['description' => 'late edit'])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/journal-entries/'.$entry->id.'/lines', [
                'debit_lines' => [],
                'credit_lines' => [],
            ])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/journal-entries/'.$entry->id.'/post')
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/journal-entries/'.$entry->id)
            ->assertStatus(403);
    }

    public function test_rewriting_the_lines_updates_keeps_and_deletes_in_one_pass(): void
    {
        $entry = $this->balancedDraft();
        $entry->load('lines');

        $debitLine = $entry->lines->firstWhere('direction', \App\Enums\AccountDirection::Debit);
        $newAccount = Account::factory()->create();

        $response = $this->actingAsApi($this->userWith(['journal_entries.edit']))
            ->apiJson('PUT', self::BASE.'/journal-entries/'.$entry->id.'/lines', [
                'debit_lines' => [
                    // Carries an id: updated in place rather than recreated.
                    ['id' => $debitLine->id, 'account_id' => $debitLine->account_id, 'amount' => 25000],
                    ['account_id' => $newAccount->id, 'amount' => 15000],
                ],
                // The credit side is dropped entirely.
                'credit_lines' => [],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.totals.lines_debit', '40000.00');
        $response->assertJsonPath('data.totals.lines_credit', '0.00');
        $response->assertJsonPath('data.totals.balanced', false);

        $this->assertSame(2, $entry->lines()->count());
        $this->assertSame(25000.0, (float) $debitLine->fresh()->amount);
    }

    public function test_a_line_with_no_account_is_ignored(): void
    {
        $entry = $this->draftEntry();
        $account = Account::factory()->create();

        $this->actingAsApi($this->userWith(['journal_entries.edit']))
            ->apiJson('PUT', self::BASE.'/journal-entries/'.$entry->id.'/lines', [
                'debit_lines' => [['account_id' => $account->id, 'amount' => 100]],
                // The empty row a form always has at the bottom.
                'credit_lines' => [['account_id' => null, 'amount' => 0]],
            ])
            ->assertOk();

        $this->assertSame(1, $entry->lines()->count());
    }

    public function test_the_entry_index_filters_and_paginates(): void
    {
        JournalEntry::factory()->count(3)->create(['status' => JournalStatus::Draft]);
        JournalEntry::factory()->count(2)->create(['status' => JournalStatus::Posted]);

        $response = $this->actingAsApi($this->userWith(['journal_entries.view']))
            ->apiGet(self::BASE.'/journal-entries?filter[status]=posted&per_page=1');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 2);
        $response->assertJsonPath('meta.pagination.per_page', 1);
    }

    public function test_the_entry_index_does_not_grow_its_query_count_with_its_rows(): void
    {
        $user = $this->userWith(['journal_entries.view']);

        $this->actingAsApi($user)->apiGet(self::BASE.'/journal-entries');

        $count = function (int $rows) use ($user): int {
            JournalEntry::query()->delete();
            JournalEntry::factory()->count($rows)->create();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAsApi($user)->apiGet(self::BASE.'/journal-entries')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(3), $count(9));
    }

    public function test_replaying_a_post_does_not_post_twice(): void
    {
        $entry = $this->balancedDraft();

        // One token for both calls — the idempotency cache is keyed on the
        // bearer token (Finding #18).
        $request = $this->actingAsApi($this->userWith(['journal_entries.post']));
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $request->apiPost(self::BASE.'/journal-entries/'.$entry->id.'/post', [], $key)->assertOk();
        $request->apiPost(self::BASE.'/journal-entries/'.$entry->id.'/post', [], $key)->assertOk();

        $this->assertSame(JournalStatus::Posted, $entry->fresh()->status);
    }

    // --------------------------------------------------------------- gates

    public function test_posting_needs_its_own_permission(): void
    {
        $entry = $this->balancedDraft();

        // Writing an entry is not committing it to the books.
        $response = $this->actingAsApi($this->userWith(['journal_entries.create', 'journal_entries.edit']))
            ->apiPost(self::BASE.'/journal-entries/'.$entry->id.'/post');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_a_token_without_the_finance_ability_is_refused(): void
    {
        $response = $this->actingAsApi($this->admin(), ['inventory'])
            ->apiGet(self::BASE.'/accounts');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/accounts')->assertStatus(401);
        $this->apiGet(self::BASE.'/journal-entries')->assertStatus(401);
    }

    // ------------------------------------------------------------- helpers

    private function draftEntry(): JournalEntry
    {
        return JournalEntry::factory()->create(['status' => JournalStatus::Draft]);
    }

    private function balancedDraft(float $debit = 40000, float $credit = 40000): JournalEntry
    {
        $entry = $this->draftEntry();

        $entry->lines()->create([
            'account_id' => Account::factory()->ofType(AccountType::Expense)->create()->id,
            'direction' => \App\Enums\AccountDirection::Debit,
            'amount' => $debit,
        ]);

        $entry->lines()->create([
            'account_id' => Account::factory()->ofType(AccountType::Asset)->create()->id,
            'direction' => \App\Enums\AccountDirection::Credit,
            'amount' => $credit,
        ]);

        return $entry->fresh();
    }
}
