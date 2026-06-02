<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\AccountDirection;
use App\Enums\ClaimStatus;
use App\Enums\JournalStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\FinancialClaim;
use App\Models\OperationPayment;
use App\Models\Project;
use App\Models\User;
use App\Services\OperationCostService;
use App\Services\OperationPaymentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function service(): OperationPaymentService
    {
        return app(OperationPaymentService::class);
    }

    public function test_records_incoming_payment_without_gl_by_default(): void
    {
        config(['operations.auto_journal_payments' => false]);
        $project = Project::factory()->active()->create();

        $payment = $this->service()->record([
            'project_id' => $project->id,
            'direction' => PaymentDirection::Incoming,
            'method' => PaymentMethod::Cash,
            'amount' => 1500,
        ]);

        $this->assertStringStartsWith('PMT-', $payment->payment_number);
        $this->assertNull($payment->journal_entry_id);
        $this->assertEquals(1500.0, app(OperationCostService::class)->received($project));
    }

    public function test_auto_journal_posts_balanced_entry_tagged_to_operation(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        config(['operations.auto_journal_payments' => true]);

        $project = Project::factory()->active()->create();
        $treasury = Account::where('code', '1010')->firstOrFail();   // الخزينة
        $customers = Account::where('code', '1200')->firstOrFail();  // العملاء

        $payment = $this->service()->record([
            'project_id' => $project->id,
            'direction' => PaymentDirection::Incoming,
            'method' => PaymentMethod::Cash,
            'amount' => 2000,
            'account_id' => $treasury->id,
        ]);

        $entry = $payment->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertEquals(2000.0, (float) $entry->total_debit);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);

        // Dr treasury / Cr customers, both tagged to the operation.
        $debit = $entry->lines->firstWhere('direction', AccountDirection::Debit);
        $credit = $entry->lines->firstWhere('direction', AccountDirection::Credit);
        $this->assertSame($treasury->id, $debit->account_id);
        $this->assertSame($customers->id, $credit->account_id);
        $this->assertSame($project->id, $debit->project_id);
        $this->assertSame($project->id, $credit->project_id);
    }

    public function test_auto_journal_disabled_skips_entry(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        config(['operations.auto_journal_payments' => false]);

        $project = Project::factory()->active()->create();
        $treasury = Account::where('code', '1010')->firstOrFail();

        $payment = $this->service()->record([
            'project_id' => $project->id,
            'direction' => PaymentDirection::Incoming,
            'method' => PaymentMethod::Cash,
            'amount' => 2000,
            'account_id' => $treasury->id,
        ]);

        $this->assertNull($payment->journal_entry_id);
    }

    public function test_payment_allocated_to_claim_collects_it_when_fully_paid(): void
    {
        config(['operations.auto_journal_payments' => false]);
        $project = Project::factory()->completed()->create();
        $claim = FinancialClaim::factory()->submitted()->create([
            'project_id' => $project->id,
            'amount' => 1000,
        ]);

        $this->service()->record([
            'project_id' => $project->id,
            'financial_claim_id' => $claim->id,
            'direction' => PaymentDirection::Incoming,
            'method' => PaymentMethod::Cash,
            'amount' => 1000,
        ]);

        $this->assertSame(ClaimStatus::Collected, $claim->fresh()->status);
    }

    public function test_partial_payment_does_not_collect_claim(): void
    {
        config(['operations.auto_journal_payments' => false]);
        $project = Project::factory()->completed()->create();
        $claim = FinancialClaim::factory()->submitted()->create([
            'project_id' => $project->id,
            'amount' => 1000,
        ]);

        $this->service()->record([
            'project_id' => $project->id,
            'financial_claim_id' => $claim->id,
            'direction' => PaymentDirection::Incoming,
            'method' => PaymentMethod::Cash,
            'amount' => 400,
        ]);

        $this->assertSame(ClaimStatus::Submitted, $claim->fresh()->status);
    }

    public function test_totals_for_project(): void
    {
        config(['operations.auto_journal_payments' => false]);
        $project = Project::factory()->active()->create();

        OperationPayment::factory()->create(['project_id' => $project->id, 'direction' => PaymentDirection::Incoming, 'amount' => 1000]);
        OperationPayment::factory()->outgoing()->create(['project_id' => $project->id, 'amount' => 300]);

        $totals = $this->service()->totalsForProject($project);

        $this->assertEquals(1000.0, $totals['received']);
        $this->assertEquals(300.0, $totals['paid']);
        $this->assertEquals(700.0, $totals['net']);
    }
}
