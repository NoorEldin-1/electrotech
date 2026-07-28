<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\ItemType;
use App\Enums\JournalStatus;
use App\Enums\WarehouseType;
use App\Enums\WorkOrderStatus;
use App\Models\Account;
use App\Models\CostCenterClosing;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\DeliveryVoucherLine;
use App\Models\DepreciationVoucher;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ReturnVoucher;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CostCenterClosingService;
use App\Services\DeliveryVoucherService;
use App\Services\InventoryService;
use App\Services\OperationCostService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إقفال مركز التكلفة (Financial Department سلايد 12): "وعند تسليم العميل بإذن
 * تسليم يتم اقفال مركز التكلفة فى حساب تكلفة البضاعة المباعة".
 */
class CostCenterClosingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Stock movements stamp `performed_by`, so the suite needs a signed-in user.
        $this->actingAs(User::factory()->create());
    }

    private function service(): CostCenterClosingService
    {
        return app(CostCenterClosingService::class);
    }

    private function seedAccounts(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function cogs(): Account
    {
        return Account::where('code', '5070')->firstOrFail();
    }

    private function inventoryAccount(): Account
    {
        return Account::where('code', '1300')->firstOrFail();
    }

    /** An operation with a delivered voucher and `$issued` of material on it. */
    private function deliveredOperation(float $issued = 1000, WorkOrderStatus $woStatus = WorkOrderStatus::Completed): Project
    {
        $project = Project::factory()->active()->create();

        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'status' => $woStatus]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => $issued]);

        DeliveryVoucher::factory()->create([
            'project_id' => $project->id,
            'status' => DeliveryVoucherStatus::Active,
            'total_value' => $issued,
        ]);

        return $project;
    }

    // ---------------------------------------------------------------- base

    public function test_inventory_consumed_is_issues_less_returns_and_write_offs(): void
    {
        $project = Project::factory()->active()->create();
        $wo = WorkOrder::factory()->create(['project_id' => $project->id]);

        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 1000]);
        // A draft issue voucher never counts.
        IssueVoucher::factory()->create(['work_order_id' => $wo->id, 'total_value' => 999]);
        ReturnVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 150]);
        // BOTH loss types credited the inventory account already, so both come off
        // the closing base — even though natural loss stays loaded on actual_cost.
        DepreciationVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 100]);
        DepreciationVoucher::factory()->natural()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 50]);

        $this->assertSame(700.0, $this->service()->inventoryConsumed($project));
        $this->assertSame(700.0, $this->service()->unclosedBalance($project));
        $this->assertSame(0.0, $this->service()->closedValue($project));
    }

    public function test_another_operations_vouchers_are_not_counted(): void
    {
        $a = Project::factory()->active()->create();
        $b = Project::factory()->active()->create();

        $woB = WorkOrder::factory()->create(['project_id' => $b->id]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $woB->id, 'total_value' => 800]);

        $this->assertSame(0.0, $this->service()->inventoryConsumed($a));
        $this->assertSame(800.0, $this->service()->inventoryConsumed($b));
    }

    // ------------------------------------------------------------- closing

    public function test_closing_posts_a_balanced_cogs_entry_and_empties_the_balance(): void
    {
        $this->seedAccounts();
        $project = $this->deliveredOperation(1000);
        $user = User::factory()->create();

        $closing = $this->service()->close($project, null, $user);

        $this->assertEqualsWithDelta(1000.0, (float) $closing->amount, 0.001);
        $this->assertFalse($closing->is_automatic);
        $this->assertSame($user->id, $closing->closed_by);

        $entry = $closing->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertTrue($entry->fresh('lines')->isBalanced());

        $debit = $entry->lines()->where('direction', AccountDirection::Debit->value)->firstOrFail();
        $credit = $entry->lines()->where('direction', AccountDirection::Credit->value)->firstOrFail();

        $this->assertSame($this->cogs()->id, $debit->account_id);
        $this->assertSame($this->inventoryAccount()->id, $credit->account_id);
        $this->assertEqualsWithDelta(1000.0, (float) $debit->amount, 0.001);

        // The cost-centre dimension rides the COGS line; inventory is a pooled
        // control account and stays untagged.
        $this->assertSame($project->id, $debit->project_id);
        $this->assertNull($credit->project_id);

        $this->assertSame(1000.0, $this->service()->closedValue($project));
        $this->assertSame(0.0, $this->service()->unclosedBalance($project));
        $this->assertTrue($this->service()->isClosed($project));
    }

    public function test_closing_does_not_inflate_the_operations_ledger_expenses(): void
    {
        $this->seedAccounts();
        $project = $this->deliveredOperation(1000);

        $before = app(OperationCostService::class)->breakdown($project);
        $this->service()->close($project, null, User::factory()->create());
        $after = app(OperationCostService::class)->breakdown($project);

        // Cost of goods sold is the RESULT of the cost centre, not an input:
        // counting the closing entry as a ledger expense would double the
        // material already carried in materials_cost.
        $this->assertSame($before['ledger_expenses'], $after['ledger_expenses']);
        $this->assertSame($before['total_cost'], $after['total_cost']);
        $this->assertSame(1000.0, $after['closed_to_cogs']);
        $this->assertSame(0.0, $after['unclosed_cost']);
    }

    public function test_other_expense_accounts_still_count_as_ledger_expenses(): void
    {
        $this->seedAccounts();
        $project = Project::factory()->active()->create();
        $expense = Account::where('code', '5010')->firstOrFail();
        $entry = JournalEntry::factory()->posted()->create();

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expense->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 250,
        ]);

        $this->assertSame(250.0, app(OperationCostService::class)->ledgerExpenses($project));
    }

    public function test_late_cost_after_a_closing_can_be_closed_by_a_second_entry(): void
    {
        $this->seedAccounts();
        $project = $this->deliveredOperation(1000);
        $this->service()->close($project, null, User::factory()->create());

        // A late issue voucher lands on the operation after it was closed.
        $wo = $project->workOrders()->first();
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 300]);

        $this->assertSame(300.0, $this->service()->unclosedBalance($project));
        $this->assertTrue($this->service()->isPartiallyClosed($project));

        $second = $this->service()->close($project, null, User::factory()->create());

        $this->assertEqualsWithDelta(300.0, (float) $second->amount, 0.001);
        $this->assertSame(1300.0, $this->service()->closedValue($project));
        $this->assertSame(2, CostCenterClosing::where('project_id', $project->id)->count());
    }

    public function test_closing_is_refused_without_an_active_delivery(): void
    {
        $this->seedAccounts();
        $project = Project::factory()->active()->create();
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'status' => WorkOrderStatus::Completed]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 500]);

        $this->expectException(\RuntimeException::class);
        $this->service()->close($project);
    }

    public function test_closing_is_refused_when_nothing_is_left_to_close(): void
    {
        $this->seedAccounts();
        $project = $this->deliveredOperation(1000);
        $this->service()->close($project);

        $this->expectException(\RuntimeException::class);
        $this->service()->close($project);
    }

    public function test_closing_is_refused_when_the_accounts_are_missing(): void
    {
        $project = $this->deliveredOperation(1000); // chart not seeded

        $this->expectException(\RuntimeException::class);
        $this->service()->close($project);
    }

    // ------------------------------------------------------------ reversal

    public function test_reversal_posts_the_opposite_entry_and_restores_the_balance(): void
    {
        $this->seedAccounts();
        $project = $this->deliveredOperation(1000);
        $closing = $this->service()->close($project, null, User::factory()->create());

        $reversal = $this->service()->reverse($closing, User::factory()->create(), 'wrong operation');

        $this->assertEqualsWithDelta(-1000.0, (float) $reversal->amount, 0.001);
        $this->assertSame($closing->id, $reversal->reverses_id);
        $this->assertSame('wrong operation', $reversal->notes);

        $entry = $reversal->journalEntry;
        $debit = $entry->lines()->where('direction', AccountDirection::Debit->value)->firstOrFail();
        $credit = $entry->lines()->where('direction', AccountDirection::Credit->value)->firstOrFail();

        // Mirror image: inventory takes the value back from COGS.
        $this->assertSame($this->inventoryAccount()->id, $debit->account_id);
        $this->assertSame($this->cogs()->id, $credit->account_id);
        $this->assertSame(JournalStatus::Posted, $entry->status);

        $this->assertSame(0.0, $this->service()->closedValue($project));
        $this->assertSame(1000.0, $this->service()->unclosedBalance($project));
        $this->assertFalse($this->service()->isClosed($project));
    }

    public function test_a_closing_cannot_be_reversed_twice(): void
    {
        $this->seedAccounts();
        $project = $this->deliveredOperation(1000);
        $closing = $this->service()->close($project);
        $reversal = $this->service()->reverse($closing);

        $this->assertTrue($closing->refresh()->isReversed());

        try {
            $this->service()->reverse($closing);
            $this->fail('Reversing an already reversed closing must be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->expectException(\RuntimeException::class);
        $this->service()->reverse($reversal);
    }

    // ------------------------------------------------- automatic on delivery

    /**
     * Drive a real delivery through the dual-approval flow.
     */
    private function activateDeliveryFor(Project $project, float $value = 500): DeliveryVoucher
    {
        $item = Item::factory()->create(['type' => ItemType::FinishedGood]);
        app(InventoryService::class)->addStock($item, 10, null, null, WarehouseType::FinishedGoods);

        $voucher = DeliveryVoucher::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'project_id' => $project->id,
            'status' => DeliveryVoucherStatus::Draft,
        ]);

        DeliveryVoucherLine::create([
            'delivery_voucher_id' => $voucher->id,
            'item_id' => $item->id,
            'quantity' => 5,
            'unit_cost' => $value / 5,
        ]);

        $service = app(DeliveryVoucherService::class);
        $service->approveTechnical($voucher->fresh('lines'), User::factory()->create());
        $service->approveFinancial($voucher->fresh('lines'), User::factory()->create());

        return $voucher->fresh();
    }

    public function test_activating_a_delivery_closes_the_cost_center_automatically(): void
    {
        $this->seedAccounts();
        $project = Project::factory()->active()->create();
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'status' => WorkOrderStatus::Completed]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 900]);

        $voucher = $this->activateDeliveryFor($project);

        $this->assertSame(DeliveryVoucherStatus::Active, $voucher->status);

        $closing = CostCenterClosing::where('project_id', $project->id)->firstOrFail();
        $this->assertEqualsWithDelta(900.0, (float) $closing->amount, 0.001);
        $this->assertTrue($closing->is_automatic);
        $this->assertNull($closing->closed_by);
        $this->assertSame($voucher->id, $closing->delivery_voucher_id);
        $this->assertSame(0.0, $this->service()->unclosedBalance($project));
    }

    public function test_open_work_orders_keep_the_cost_center_open(): void
    {
        $this->seedAccounts();
        $project = Project::factory()->active()->create();
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'status' => WorkOrderStatus::InProgress]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 900]);

        $voucher = $this->activateDeliveryFor($project);

        $this->assertSame(DeliveryVoucherStatus::Active, $voucher->status);
        $this->assertSame(0, CostCenterClosing::where('project_id', $project->id)->count());
        $this->assertSame(900.0, $this->service()->unclosedBalance($project));
    }

    public function test_the_automatic_closing_can_be_switched_off(): void
    {
        config(['operations.auto_close_cost_center' => false]);
        $this->seedAccounts();
        $project = Project::factory()->active()->create();
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'status' => WorkOrderStatus::Completed]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 900]);

        $this->activateDeliveryFor($project);

        $this->assertSame(0, CostCenterClosing::where('project_id', $project->id)->count());
    }

    public function test_a_missing_chart_of_accounts_never_breaks_the_delivery(): void
    {
        // No chart seeded on purpose.
        $project = Project::factory()->active()->create();
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'status' => WorkOrderStatus::Completed]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => 900]);

        $voucher = $this->activateDeliveryFor($project);

        $this->assertSame(DeliveryVoucherStatus::Active, $voucher->status);
        $this->assertEquals(500, (float) $voucher->total_value);
        $this->assertSame(0, CostCenterClosing::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_delivery_without_an_operation_is_ignored(): void
    {
        $this->seedAccounts();
        $item = Item::factory()->create(['type' => ItemType::FinishedGood]);
        app(InventoryService::class)->addStock($item, 10, null, null, WarehouseType::FinishedGoods);

        $voucher = DeliveryVoucher::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'project_id' => null,
            'status' => DeliveryVoucherStatus::Draft,
        ]);
        DeliveryVoucherLine::create([
            'delivery_voucher_id' => $voucher->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'unit_cost' => 50,
        ]);

        $service = app(DeliveryVoucherService::class);
        $service->approveTechnical($voucher->fresh('lines'), User::factory()->create());
        $service->approveFinancial($voucher->fresh('lines'), User::factory()->create());

        $this->assertSame(DeliveryVoucherStatus::Active, $voucher->fresh()->status);
        $this->assertSame(0, CostCenterClosing::count());
    }

    // ------------------------------------------------------------ plumbing

    public function test_the_cogs_account_is_in_the_seeded_chart(): void
    {
        $this->seedAccounts();

        $account = Account::where('code', '5070')->first();

        $this->assertNotNull($account, 'سلايد 8 lists تكلفة البضاعة المباعة among the expense accounts.');
        $this->assertSame(AccountType::Expense, $account->type);
    }

    public function test_closing_translations_exist_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ([
                'resources.operations_cost.closing.heading',
                'resources.operations_cost.closing.status',
                'resources.operations_cost.closing.states.closed',
                'resources.operations_cost.closing.actions.close',
                'resources.operations_cost.closing.actions.reverse',
                'resources.operations_cost.closing.columns.amount',
                'resources.operations_cost.cards.inventory_consumed',
                'resources.operations_cost.cards.closed_to_cogs',
                'resources.operations_cost.cards.unclosed_cost',
                'resources.roles.permissions.operations.close_cost_center',
                'errors.cost_center.no_delivery',
                'errors.cost_center.nothing_to_close',
                'errors.cost_center.accounts_missing',
                'errors.cost_center.is_reversal',
                'errors.cost_center.already_reversed',
            ] as $key) {
                $this->assertNotSame($key, __($key), "Missing [{$locale}] translation: {$key}");
            }
        }
    }
}
