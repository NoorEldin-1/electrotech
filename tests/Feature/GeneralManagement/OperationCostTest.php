<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\DeliveryVoucher;
use App\Models\IssueVoucher;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use App\Services\OperationCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationCostTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OperationCostService
    {
        return app(OperationCostService::class);
    }

    public function test_empty_operation_has_zero_cost_and_no_margin(): void
    {
        $project = Project::factory()->active()->create(['estimated_budget' => 1000]);

        $b = $this->service()->breakdown($project);

        $this->assertSame(0.0, $b['materials_cost']);
        $this->assertSame(0.0, $b['total_cost']);
        $this->assertSame(0.0, $b['revenue']);
        $this->assertSame(0.0, $b['profit']);
        $this->assertNull($b['margin_percent']);
        $this->assertSame(1000.0, $b['estimated_budget']);
    }

    public function test_breakdown_aggregates_all_components(): void
    {
        $project = Project::factory()->active()->create(['estimated_budget' => 5000]);

        // Materials: a posted issue voucher whose work order is on this project.
        $wo = WorkOrder::factory()->create(['project_id' => $project->id]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 500]);
        // A draft issue voucher must NOT count.
        IssueVoucher::factory()->create(['work_order_id' => $wo->id, 'total_value' => 999]);

        // Ledger expenses: posted entry, debit line on an expense account tagged to the operation.
        $expense = Account::factory()->ofType(AccountType::Expense)->create();
        $entry = JournalEntry::factory()->posted()->create();
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expense->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 300,
        ]);
        // Untagged expense line must NOT count.
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expense->id,
            'project_id' => null,
            'direction' => AccountDirection::Debit,
            'amount' => 111,
        ]);

        // Revenue: an active delivery voucher for the operation.
        DeliveryVoucher::factory()->create([
            'project_id' => $project->id,
            'status' => DeliveryVoucherStatus::Active,
            'total_value' => 2000,
        ]);

        // Purchases reference (cancelled is excluded; not added into total cost).
        PurchaseOrder::factory()->create(['project_id' => $project->id, 'status' => PurchaseOrderStatus::Submitted, 'total_amount' => 700]);
        PurchaseOrder::factory()->create(['project_id' => $project->id, 'status' => PurchaseOrderStatus::Cancelled, 'total_amount' => 999]);

        $b = $this->service()->breakdown($project);

        $this->assertSame(500.0, $b['materials_cost']);
        $this->assertSame(300.0, $b['ledger_expenses']);
        $this->assertSame(800.0, $b['total_cost']);
        $this->assertSame(2000.0, $b['revenue']);
        $this->assertSame(1200.0, $b['profit']);
        $this->assertSame(700.0, $b['purchases_reference']);
        $this->assertEqualsWithDelta(60.0, $b['margin_percent'], 0.001);
    }

    public function test_draft_journal_entries_are_excluded_from_ledger_expenses(): void
    {
        $project = Project::factory()->active()->create();
        $expense = Account::factory()->ofType(AccountType::Expense)->create();
        $draft = JournalEntry::factory()->create(); // draft by default

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $draft->id,
            'account_id' => $expense->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 400,
        ]);

        $this->assertSame(0.0, $this->service()->ledgerExpenses($project));
    }

    public function test_cost_of_another_operation_is_not_counted(): void
    {
        $a = Project::factory()->active()->create();
        $b = Project::factory()->active()->create();

        $woB = WorkOrder::factory()->create(['project_id' => $b->id]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $woB->id, 'total_value' => 1000]);

        $this->assertSame(0.0, $this->service()->materialsCost($a));
        $this->assertSame(1000.0, $this->service()->materialsCost($b));
    }
}
