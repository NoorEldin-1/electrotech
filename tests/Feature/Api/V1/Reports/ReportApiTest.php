<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Reports;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\JournalStatus;
use App\Enums\StatementSection;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\QualitySheet;
use App\Models\WorkOrder;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * The financial reports and the printable documents.
 *
 * The claims under test are about the CONTRACT rather than the accounting: the
 * figures leave as decimal strings, accounts are named and not dumped, each
 * report keeps its own permission, and the PDFs stream bytes rather than an
 * envelope.
 */
class ReportApiTest extends ApiTestCase
{
    // --------------------------------------------------------------- reports

    public function test_the_trial_balance_is_grouped_by_currency_with_its_own_totals(): void
    {
        $this->postedEntry(40000);

        $response = $this->actingAsApi($this->userWith(['trial_balance.view']))
            ->apiGet(self::BASE.'/reports/trial-balance');

        $response->assertOk();
        $this->assertItemEnvelope($response);

        // Totalling accounts in different currencies into one column would
        // produce a number that balances by accident or not at all.
        $response->assertJsonPath('data.currencies.EGP.balanced', true);
        $response->assertJsonPath('data.currencies.EGP.total_debit', '40000.00');
        $response->assertJsonPath('data.currencies.EGP.total_credit', '40000.00');

        // An account inside a report is named, never dumped — four fields, not
        // the whole record with its opening balance and its notes.
        $account = $response->json('data.currencies.EGP.rows.0.account');
        $this->assertSame(['id', 'code', 'name', 'type'], array_keys($account));
    }

    public function test_money_leaves_a_report_as_a_decimal_string(): void
    {
        $entry = $this->postedEntry(40000);
        $debitAccountId = $entry->lines->firstWhere('direction', AccountDirection::Debit)->account_id;

        $response = $this->actingAsApi($this->userWith(['trial_balance.view']))
            ->apiGet(self::BASE.'/reports/trial-balance');

        // The rows are ordered by account code, so the row is found by id
        // rather than by position.
        $row = collect($response->json('data.currencies.EGP.rows'))
            ->firstWhere('account.id', $debitAccountId);

        // A JSON number is a binary double in Dart; a statement re-summed on
        // the client would drift from the one the ledger holds.
        $this->assertIsString($row['debit']);
        $this->assertSame('40000.00', $row['debit']);
    }

    public function test_the_general_ledger_carries_opening_closing_and_a_running_balance(): void
    {
        $entry = $this->postedEntry(40000);
        $accountId = $entry->lines->firstWhere('direction', AccountDirection::Debit)->account_id;

        $response = $this->actingAsApi($this->userWith(['general_ledger.view']))
            ->apiGet(self::BASE.'/reports/general-ledger?account_id='.$accountId);

        $response->assertOk();
        $response->assertJsonPath('data.account.id', $accountId);
        $response->assertJsonPath('data.opening_balance', '0.00');
        $response->assertJsonPath('data.closing_balance', '40000.00');
        $response->assertJsonPath('data.totals.debit', '40000.00');
        $response->assertJsonPath('data.rows.0.balance', '40000.00');
    }

    public function test_a_backwards_period_is_refused_rather_than_returning_nothing(): void
    {
        $account = Account::factory()->create();

        $response = $this->actingAsApi($this->userWith(['general_ledger.view']))
            ->apiGet(self::BASE.'/reports/general-ledger?account_id='.$account->id.'&from=2026-06-30&to=2026-01-01');

        // An empty report looks exactly like a true answer — "this account had
        // no movement" rather than "you asked the question wrong".
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['to']]]);
    }

    public function test_a_draft_entry_never_reaches_a_report(): void
    {
        $this->balancedEntry(40000, JournalStatus::Draft);

        $response = $this->actingAsApi($this->userWith(['trial_balance.view']))
            ->apiGet(self::BASE.'/reports/trial-balance');

        $response->assertOk();

        // Only posted entries are in the books. A draft appearing in the trial
        // balance would make the report a draft too.
        $this->assertSame([], $response->json('data.currencies'));
    }

    public function test_the_statements_answer_and_report_whether_they_reconcile(): void
    {
        $this->postedEntry(40000);

        $this->actingAsApi($this->userWith(['income_statement.view']))
            ->apiGet(self::BASE.'/reports/income-statement?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonStructure(['data' => ['net_sales', 'cost_of_sales', 'gross_profit', 'net_profit']]);

        // The balance sheet is only trustworthy if the two halves meet, so it
        // says whether they do rather than leaving a reader to check.
        $this->actingAsApi($this->userWith(['balance_sheet.view']))
            ->apiGet(self::BASE.'/reports/balance-sheet?as_of=2026-12-31')
            ->assertOk()
            ->assertJsonStructure(['data' => ['as_of', 'working_capital', 'total_investment', 'balanced', 'difference']]);

        // Likewise a cash flow statement that does not reconcile against the
        // cash the ledger holds is not a presentation issue.
        $this->actingAsApi($this->userWith(['cash_flow_statement.view']))
            ->apiGet(self::BASE.'/reports/cash-flow?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonStructure(['data' => ['net_profit', 'net_change', 'reconciled', 'reconciliation_difference']]);

        $this->actingAsApi($this->userWith(['operating_statement.view']))
            ->apiGet(self::BASE.'/reports/operating-statement')
            ->assertOk()
            ->assertJsonStructure(['data' => ['rows', 'total']]);

        $this->actingAsApi($this->userWith(['journal_daybook.view']))
            ->apiGet(self::BASE.'/reports/journal-daybook?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonStructure(['data' => ['accounts', 'rows', 'column_totals']]);
    }

    public function test_an_operations_cost_file_and_timeline_read(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['operations.view_cost']))
            ->apiGet(self::BASE.'/projects/'.$project->id.'/cost-breakdown')
            ->assertOk()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonStructure(['data' => ['estimated_budget', 'materials_cost', 'total_cost', 'profit']]);

        $timeline = $this->actingAsApi($this->userWith(['operations.overview']))
            ->apiGet(self::BASE.'/projects/'.$project->id.'/timeline');

        $timeline->assertOk();
        $timeline->assertJsonStructure(['data' => ['current_stage', 'stages']]);

        // Unreached stages stay in the list: a timeline that only showed what
        // happened could not show what is next.
        $this->assertNotEmpty($timeline->json('data.stages'));
        $this->assertArrayHasKey('reached', $timeline->json('data.stages.0'));
    }

    public function test_each_report_carries_its_own_permission(): void
    {
        // Holding the trial balance is not holding the balance sheet.
        $user = $this->userWith(['trial_balance.view']);

        $this->actingAsApi($user)->apiGet(self::BASE.'/reports/trial-balance')->assertOk();

        $response = $this->actingAsApi($user)->apiGet(self::BASE.'/reports/balance-sheet');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_reports_need_the_reports_token_ability(): void
    {
        $response = $this->actingAsApi($this->admin(), ['finance'])
            ->apiGet(self::BASE.'/reports/trial-balance');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    // ------------------------------------------------------------- documents

    public function test_a_document_streams_a_pdf_rather_than_an_envelope(): void
    {
        $sheet = QualitySheet::factory()->create();

        $response = $this->actingAsApi($this->userWith(['quality_sheets.print']))
            ->apiGet(self::BASE.'/documents/quality-sheets/'.$sheet->id);

        $response->assertOk();

        // The one place in the API where the response is not the JSON
        // envelope: a client wants bytes for its viewer, not base64 in JSON.
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_printing_keeps_its_own_permission(): void
    {
        $sheet = QualitySheet::factory()->create();

        // Being able to READ a quality sheet is not being able to print the
        // certificate.
        $response = $this->actingAsApi($this->userWith(['quality_sheets.view']))
            ->apiGet(self::BASE.'/documents/quality-sheets/'.$sheet->id);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_a_document_can_be_printed_in_a_chosen_language(): void
    {
        $offer = ProjectOffer::factory()->create();

        // The customer's copy of an offer is not always in the language of
        // whoever pressed print.
        $this->actingAsApi($this->userWith(['project_offers.print']))
            ->apiGet(self::BASE.'/documents/offers/'.$offer->id.'?lang=ar')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_work_order_variance_document_renders(): void
    {
        $order = WorkOrder::factory()->create();

        $this->actingAsApi($this->userWith(['work_orders.view']))
            ->apiGet(self::BASE.'/documents/work-orders/'.$order->id.'/material-variance')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/reports/trial-balance')->assertStatus(401);
        $this->apiGet(self::BASE.'/documents/financial-statements')->assertStatus(401);
    }

    // ------------------------------------------------------------- helpers

    private function balancedEntry(float $amount, JournalStatus $status): JournalEntry
    {
        $entry = JournalEntry::factory()->create([
            'status' => $status,
            'currency' => 'EGP',
            'entry_date' => now(),
        ]);

        $entry->lines()->create([
            'account_id' => Account::factory()->ofType(AccountType::Expense)->create([
                'currency' => 'EGP',
                'statement_section' => StatementSection::CostOfSales,
            ])->id,
            'direction' => AccountDirection::Debit,
            'amount' => $amount,
        ]);

        $entry->lines()->create([
            'account_id' => Account::factory()->ofType(AccountType::Asset)->create([
                'currency' => 'EGP',
                'statement_section' => StatementSection::CurrentAssets,
            ])->id,
            'direction' => AccountDirection::Credit,
            'amount' => $amount,
        ]);

        return $entry->fresh()->load('lines');
    }

    private function postedEntry(float $amount): JournalEntry
    {
        $entry = $this->balancedEntry($amount, JournalStatus::Draft);

        app(\App\Services\JournalEntryService::class)->post($entry);

        return $entry->fresh()->load('lines');
    }
}
