<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\ItemType;
use App\Enums\JournalStatus;
use App\Enums\LossType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Enums\WorkOrderStatus;
use App\Http\Resources\Api\V1\Finance\JournalEntryResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AdditionVoucher;
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
use App\Services\AdditionVoucherService;
use App\Services\CostCenterClosingService;
use App\Services\DeliveryVoucherService;
use App\Services\DepreciationVoucherService;
use App\Services\InventoryJournalService;
use App\Services\InventoryService;
use App\Services\IssueVoucherService;
use App\Services\OperationCostService;
use App\Services\ReturnVoucherService;
use App\Services\TrialBalanceService;
use App\Services\WorkOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final_Modifications Task 7 (سلايد 2–3): every document that moves stock now
 * books its own balanced, posted journal entry against the split inventory
 * accounts — 1310 raw / 1320 work in progress / 1330 finished goods — and each
 * entry is idempotent per source document.
 */
class InventoryJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        // Stock movements stamp `performed_by`, so the suite needs a signed-in user.
        $this->actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------- helpers

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** Net debit balance of an account over every POSTED entry. */
    private function balance(string $code): float
    {
        $lines = JournalEntryLine::query()
            ->where('account_id', $this->account($code)->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalStatus::Posted->value))
            ->get();

        return round(
            (float) $lines->where('direction', AccountDirection::Debit)->sum('amount')
            - (float) $lines->where('direction', AccountDirection::Credit)->sum('amount'),
            2,
        );
    }

    private function entryFor(Model $source, string $event = InventoryJournalService::EVENT_POST): JournalEntry
    {
        return JournalEntry::query()->forSource($source, $event)->with('lines.account')->firstOrFail();
    }

    private function debitLine(JournalEntry $entry): JournalEntryLine
    {
        return $entry->lines->firstWhere('direction', AccountDirection::Debit);
    }

    private function creditLine(JournalEntry $entry): JournalEntryLine
    {
        return $entry->lines->firstWhere('direction', AccountDirection::Credit);
    }

    private function rawItem(float $cost = 4): Item
    {
        return Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => $cost, 'is_scrap' => false]);
    }

    /**
     * Receive goods through a real addition voucher.
     *
     * @param  array<int, array{0: Item, 1: float, 2: float}>  $lines  [item, quantity, unit cost]
     * @param  array<string, mixed>  $overrides
     */
    private function receive(array $lines, array $overrides = []): AdditionVoucher
    {
        $voucher = AdditionVoucher::factory()->uninvoiced()->create($overrides);

        foreach ($lines as [$item, $quantity, $unitCost]) {
            $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]);
        }

        app(AdditionVoucherService::class)->post($voucher->fresh('lines'));

        return $voucher->fresh();
    }

    /** An approved order that is mid-manufacture, on its own operation. */
    private function workOrder(?Project $project = null, array $overrides = []): WorkOrder
    {
        $project ??= Project::factory()->active()->create(['actual_cost' => 0]);

        return WorkOrder::factory()->approved()->create(array_merge([
            'project_id' => $project->id,
            'bom_id' => null,
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now()->subHour(),
            'planned_quantity' => 10,
            'manufacturing_finished_at' => null,
        ], $overrides));
    }

    private function issue(WorkOrder $wo, Item $item, float $quantity, float $unitCost): IssueVoucher
    {
        $voucher = IssueVoucher::factory()->create(['work_order_id' => $wo->id]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]);

        app(IssueVoucherService::class)->post($voucher->fresh('lines'));

        return $voucher->fresh();
    }

    private function giveBack(WorkOrder $wo, Item $item, float $quantity, float $unitCost): ReturnVoucher
    {
        $voucher = ReturnVoucher::factory()->create(['work_order_id' => $wo->id]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]);

        app(ReturnVoucherService::class)->post($voucher->fresh('lines'));

        return $voucher->fresh();
    }

    private function writeOff(WorkOrder $wo, Item $item, float $quantity, float $unitCost, LossType $type = LossType::Abnormal): DepreciationVoucher
    {
        $voucher = DepreciationVoucher::factory()->create(['work_order_id' => $wo->id, 'loss_type' => $type]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]);

        app(DepreciationVoucherService::class)->post($voucher->fresh('lines'));

        return $voucher->fresh();
    }

    /** Drive a real delivery through the dual-approval flow. */
    private function deliver(Project $project): DeliveryVoucher
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
            'unit_cost' => 100,
        ]);

        $service = app(DeliveryVoucherService::class);
        $service->approveTechnical($voucher->fresh('lines'), User::factory()->create());
        $service->approveFinancial($voucher->fresh('lines'), User::factory()->create());

        return $voucher->fresh();
    }

    // ------------------------------------------------------------ addition

    public function test_an_addition_voucher_books_stock_to_the_raw_store_and_credits_the_suppliers(): void
    {
        $voucher = $this->receive([[$this->rawItem(), 50, 4]]);

        $entry = $this->entryFor($voucher);

        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame($voucher->voucher_number, $entry->document_number);

        $debit = $this->debitLine($entry);
        $credit = $this->creditLine($entry);

        $this->assertSame('1310', $debit->account->code);
        $this->assertSame('2010', $credit->account->code);
        $this->assertEqualsWithDelta(200.0, (float) $debit->amount, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $credit->amount, 0.001);
        // The raw store is a pooled control account — never tagged.
        $this->assertNull($debit->project_id);

        // The source is recorded on the entry itself.
        $this->assertSame(AdditionVoucher::class, $entry->source_type);
        $this->assertSame($voucher->id, $entry->source_id);
        $this->assertSame('post', $entry->source_event);

        $this->assertSame(200.0, $this->balance('1310'));
        $this->assertSame(-200.0, $this->balance('2010'));

        // The supplier SUB-LEDGER row is a different ledger and still exists once.
        $this->assertSame(1, AccountEntry::where('reference_id', $voucher->id)->count());
    }

    public function test_every_line_is_booked_to_the_store_of_the_warehouse_it_lands_in(): void
    {
        $raw = $this->rawItem();
        $finished = Item::factory()->create(['type' => ItemType::FinishedGood]);

        $voucher = $this->receive([[$raw, 10, 4], [$finished, 5, 10]]);

        $entry = $this->entryFor($voucher);
        $debits = $entry->lines->where('direction', AccountDirection::Debit);

        $this->assertCount(2, $debits);
        $this->assertEqualsWithDelta(40.0, (float) $debits->first(fn ($l) => $l->account->code === '1310')->amount, 0.001);
        // A purchased finished good belongs on 1330, not on the raw store.
        $this->assertEqualsWithDelta(50.0, (float) $debits->first(fn ($l) => $l->account->code === '1330')->amount, 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $this->creditLine($entry)->amount, 0.001);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_the_receipt_is_booked_at_stock_value_not_at_the_invoice_value(): void
    {
        // The invoice (VAT included, say) is 999 but only 200 of stock arrived.
        $voucher = $this->receive([[$this->rawItem(), 50, 4]], [
            'invoice_number' => 'INV-1',
            'invoice_value' => 999,
        ]);

        $entry = $this->entryFor($voucher);

        $this->assertEqualsWithDelta(200.0, (float) $this->debitLine($entry)->amount, 0.001);

        // The sub-ledger carries the invoice; the difference is what the
        // invoice-vs-receipt reconciliation flags — it is not capitalised.
        $this->assertEqualsWithDelta(999.0, (float) AccountEntry::where('reference_id', $voucher->id)->value('amount'), 0.001);
        $this->assertSame(1, JournalEntry::query()->forSource($voucher)->count());
    }

    public function test_a_receipt_without_a_registered_supplier_still_books_the_liability(): void
    {
        $voucher = $this->receive([[$this->rawItem(), 10, 4]], [
            'supplier_id' => null,
            'supplier_name' => 'Local shop',
        ]);

        $entry = $this->entryFor($voucher);

        $this->assertSame('2010', $this->creditLine($entry)->account->code);
        $this->assertSame(0, AccountEntry::where('reference_id', $voucher->id)->count());
    }

    public function test_an_addition_is_booked_once_however_often_it_is_retried(): void
    {
        $voucher = $this->receive([[$this->rawItem(), 50, 4]]);
        $first = $this->entryFor($voucher);

        $again = app(InventoryJournalService::class)->recordAddition($voucher->fresh());
        $this->assertSame($first->id, $again->id);

        try {
            app(AdditionVoucherService::class)->post($voucher->fresh());
            $this->fail('A posted voucher cannot be posted again.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, JournalEntry::query()->forSource($voucher)->count());
        $this->assertSame(200.0, $this->balance('1310'));
    }

    public function test_missing_store_accounts_skip_the_journal_but_never_block_the_voucher(): void
    {
        Account::query()->delete(); // empty chart

        $item = $this->rawItem();
        $voucher = $this->receive([[$item, 50, 4]]);

        $this->assertSame(VoucherStatus::Posted, $voucher->status);
        $this->assertEquals(50, $item->fresh()->quantityIn(WarehouseType::RawMaterials));
        $this->assertSame(0, JournalEntry::count());
    }

    // --------------------------------------------------------------- issue

    public function test_an_issue_voucher_moves_cost_from_raw_to_wip_tagged_to_the_operation(): void
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $wo = $this->workOrder($project);
        $item = $this->rawItem();
        $this->receive([[$item, 50, 4]]);

        $voucher = $this->issue($wo, $item, 10, 4);

        $entry = $this->entryFor($voucher);
        $debit = $this->debitLine($entry);
        $credit = $this->creditLine($entry);

        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertSame('1320', $debit->account->code);
        $this->assertSame('1310', $credit->account->code);
        $this->assertEqualsWithDelta(40.0, (float) $debit->amount, 0.001);
        $this->assertSame($project->id, $debit->project_id, 'WIP is tracked per operation.');
        $this->assertNull($credit->project_id);

        $this->assertSame(160.0, $this->balance('1310'));
        $this->assertSame(40.0, $this->balance('1320'));
        // The stock card and the ledger agree.
        $this->assertEquals(10, $item->fresh()->quantityIn(WarehouseType::WorkInProgress));
    }

    // -------------------------------------------------------------- return

    public function test_a_return_voucher_mirrors_the_issue(): void
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $wo = $this->workOrder($project);
        $item = $this->rawItem();
        $this->receive([[$item, 50, 4]]);
        $this->issue($wo, $item, 10, 4);

        $voucher = $this->giveBack($wo, $item, 3, 4);

        $entry = $this->entryFor($voucher);
        $debit = $this->debitLine($entry);
        $credit = $this->creditLine($entry);

        $this->assertSame('1310', $debit->account->code);
        $this->assertSame('1320', $credit->account->code);
        $this->assertEqualsWithDelta(12.0, (float) $debit->amount, 0.001);
        $this->assertNull($debit->project_id);
        $this->assertSame($project->id, $credit->project_id);

        $this->assertSame(172.0, $this->balance('1310'));
        $this->assertSame(28.0, $this->balance('1320'));
    }

    // ------------------------------------------------------------ write-off

    public function test_a_write_off_credits_wip_and_leaves_the_loss_untagged(): void
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $wo = $this->workOrder($project);
        $item = $this->rawItem();
        $this->receive([[$item, 50, 4]]);
        $this->issue($wo, $item, 10, 4);

        $voucher = $this->writeOff($wo, $item, 2, 4, LossType::Natural);

        $entry = $this->entryFor($voucher);

        $this->assertSame('5010', $this->debitLine($entry)->account->code);
        $this->assertSame('1320', $this->creditLine($entry)->account->code);
        $this->assertNull($this->debitLine($entry)->project_id);
        $this->assertSame($project->id, $this->creditLine($entry)->project_id);
        $this->assertSame($entry->id, $voucher->journal_entry_id);

        $this->assertSame(32.0, $this->balance('1320'));
        $this->assertSame(8.0, $this->balance('5010'));
    }

    public function test_a_write_off_falls_back_to_the_legacy_inventory_account_on_an_unmigrated_chart(): void
    {
        // A chart that predates the split: 1300 and the loss account only.
        Account::whereIn('code', ['1310', '1320', '1330'])->delete();

        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $wo = $this->workOrder($project);
        $item = $this->rawItem();
        app(InventoryService::class)->addStock($item, 10, null, null, WarehouseType::WorkInProgress);

        $voucher = $this->writeOff($wo, $item, 3, 4);

        $entry = $this->entryFor($voucher);

        $this->assertSame('5060', $this->debitLine($entry)->account->code);
        $this->assertSame('1300', $this->creditLine($entry)->account->code);
        $this->assertNull($this->creditLine($entry)->project_id);
    }

    // -------------------------------------------------------------- finish

    /**
     * An order that has had 10 units issued, 2 returned and 1 written off, so
     * 28 of material cost is sitting in work in progress.
     *
     * @return array{0: WorkOrder, 1: Item, 2: Project}
     */
    private function orderWithTwentyEightInWip(): array
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $wo = $this->workOrder($project);
        $item = $this->rawItem();

        $this->receive([[$item, 50, 4]]);
        $this->issue($wo, $item, 10, 4);
        $this->giveBack($wo, $item, 2, 4);
        $this->writeOff($wo, $item, 1, 4);

        return [$wo, $item, $project];
    }

    public function test_finishing_manufacturing_moves_the_accumulated_cost_to_finished_goods(): void
    {
        [$wo, , $project] = $this->orderWithTwentyEightInWip();
        $this->assertSame(28.0, $this->balance('1320'));

        app(WorkOrderService::class)->finishManufacturing($wo);

        $entry = $this->entryFor($wo->fresh(), InventoryJournalService::EVENT_FINISH);

        // issues 40 − returns 8 − write-offs 4.
        $this->assertSame('1330', $this->debitLine($entry)->account->code);
        $this->assertSame('1320', $this->creditLine($entry)->account->code);
        $this->assertEqualsWithDelta(28.0, (float) $this->debitLine($entry)->amount, 0.001);
        $this->assertSame($project->id, $this->creditLine($entry)->project_id);
        $this->assertSame(WorkOrder::class, $entry->source_type);

        $this->assertSame(0.0, $this->balance('1320'));
        $this->assertSame(28.0, $this->balance('1330'));
    }

    public function test_the_finish_entry_is_booked_once(): void
    {
        [$wo] = $this->orderWithTwentyEightInWip();

        app(WorkOrderService::class)->finishManufacturing($wo);
        app(WorkOrderService::class)->finishManufacturing($wo->fresh()); // silent retry

        $first = $this->entryFor($wo->fresh(), InventoryJournalService::EVENT_FINISH);
        $again = app(InventoryJournalService::class)->recordManufacturingFinish($wo->fresh());

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, JournalEntry::query()->forSource($wo, InventoryJournalService::EVENT_FINISH)->count());
        $this->assertSame(28.0, $this->balance('1330'));
    }

    public function test_an_order_with_no_cost_posts_no_finish_entry(): void
    {
        $wo = $this->workOrder();

        app(WorkOrderService::class)->finishManufacturing($wo);

        $this->assertTrue($wo->fresh()->isManufacturingFinished());
        $this->assertSame(0, JournalEntry::query()->forSource($wo)->count());
        $this->assertFalse(app(InventoryJournalService::class)->isFinishedInBooks($wo));
    }

    public function test_vouchers_after_the_finish_go_straight_to_finished_goods(): void
    {
        [$wo, $item] = $this->orderWithTwentyEightInWip();
        app(WorkOrderService::class)->finishManufacturing($wo);

        // A late return and a late issue: the cost is already in finished goods.
        $return = $this->giveBack($wo->fresh(), $item, 1, 4);
        $issue = $this->issue($wo->fresh(), $item, 2, 4);

        $returnEntry = $this->entryFor($return);
        $this->assertSame('1310', $this->debitLine($returnEntry)->account->code);
        $this->assertSame('1330', $this->creditLine($returnEntry)->account->code);
        $this->assertNull($this->creditLine($returnEntry)->project_id);

        $issueEntry = $this->entryFor($issue);
        $this->assertSame('1330', $this->debitLine($issueEntry)->account->code);
        $this->assertSame('1310', $this->creditLine($issueEntry)->account->code);

        // Work in progress stays empty for an order that is done, and finished
        // goods holds exactly what the order has cost: 28 − 4 + 8.
        $this->assertSame(0.0, $this->balance('1320'));
        $this->assertSame(32.0, $this->balance('1330'));
        $this->assertEqualsWithDelta(32.0, app(InventoryJournalService::class)->costCarried($wo->fresh()), 0.001);
    }

    public function test_completing_an_order_that_never_pressed_the_finish_button_still_moves_the_cost(): void
    {
        $finished = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $wo = $this->workOrder(null, ['output_item_id' => $finished->id]);
        $item = $this->rawItem();
        $this->receive([[$item, 50, 4]]);
        $this->issue($wo, $item, 10, 4);

        $wos = app(WorkOrderService::class);
        $wos->submitForQa($wo, [['produced_quantity' => 8, 'waste_quantity' => 2]]);
        $wos->complete($wo->fresh());

        $this->assertNull($wo->fresh()->manufacturing_finished_at);

        $entry = $this->entryFor($wo->fresh(), InventoryJournalService::EVENT_FINISH);
        $this->assertEqualsWithDelta(40.0, (float) $this->debitLine($entry)->amount, 0.001);
        $this->assertSame(0.0, $this->balance('1320'));
        $this->assertSame(40.0, $this->balance('1330'));
    }

    // ------------------------------------------------------------ reversal

    public function test_a_reversal_posts_the_mirror_entry_once_and_restores_the_balances(): void
    {
        $wo = $this->workOrder();
        $item = $this->rawItem();
        $this->receive([[$item, 50, 4]]);
        $voucher = $this->issue($wo, $item, 10, 4);
        $original = $this->entryFor($voucher);

        $service = app(InventoryJournalService::class);
        $reversal = $service->reverse($voucher);

        $this->assertNotNull($reversal);
        $this->assertSame(JournalStatus::Posted, $reversal->status);
        $this->assertSame('reversal:post', $reversal->source_event);
        $this->assertSame($voucher->id, $reversal->source_id);

        $reversal->load('lines.account');
        $this->assertSame('1310', $this->debitLine($reversal)->account->code);
        $this->assertSame('1320', $this->creditLine($reversal)->account->code);
        $this->assertEqualsWithDelta(40.0, (float) $this->debitLine($reversal)->amount, 0.001);
        $this->assertSame($wo->project_id, $this->creditLine($reversal)->project_id);

        $this->assertSame(200.0, $this->balance('1310'));
        $this->assertSame(0.0, $this->balance('1320'));

        // Reversing again returns the first reversal; the original is untouched.
        $this->assertSame($reversal->id, $service->reverse($voucher)->id);
        $this->assertSame(2, JournalEntry::query()->forSource($voucher)->count());
        $this->assertSame(JournalStatus::Posted, $original->fresh()->status);
    }

    public function test_reversing_a_document_that_booked_nothing_is_a_no_op(): void
    {
        $unbooked = IssueVoucher::factory()->posted()->create(['total_value' => 100]);

        $this->assertNull(app(InventoryJournalService::class)->reverse($unbooked));
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_reversing_the_finish_sends_later_vouchers_back_to_wip(): void
    {
        [$wo, $item] = $this->orderWithTwentyEightInWip();
        app(WorkOrderService::class)->finishManufacturing($wo);

        $service = app(InventoryJournalService::class);
        $this->assertTrue($service->isFinishedInBooks($wo));

        $service->reverse($wo->fresh(), InventoryJournalService::EVENT_FINISH);

        $this->assertFalse($service->isFinishedInBooks($wo->fresh()));
        $this->assertSame(28.0, $this->balance('1320'));
        $this->assertSame(0.0, $this->balance('1330'));
    }

    // ----------------------------------------------------- database guarantee

    public function test_the_database_refuses_a_second_entry_for_the_same_source_and_event(): void
    {
        JournalEntry::factory()->create(['source_type' => 'App\\Models\\IssueVoucher', 'source_id' => 7, 'source_event' => 'post']);

        $this->expectException(QueryException::class);
        JournalEntry::factory()->create(['source_type' => 'App\\Models\\IssueVoucher', 'source_id' => 7, 'source_event' => 'post']);
    }

    public function test_manual_entries_carry_no_source_and_never_collide(): void
    {
        JournalEntry::factory()->count(3)->create();

        $this->assertSame(3, JournalEntry::whereNull('source_type')->count());
    }

    public function test_the_api_resource_exposes_the_source_of_an_automatic_entry_only(): void
    {
        $voucher = $this->receive([[$this->rawItem(), 10, 4]]);
        $automatic = $this->entryFor($voucher);
        $manual = JournalEntry::factory()->create();

        $this->assertSame([
            'type' => 'AdditionVoucher',
            'id' => $voucher->id,
            'event' => 'post',
        ], (new JournalEntryResource($automatic))->resolve()['source']);

        $this->assertArrayNotHasKey('source', (new JournalEntryResource($manual))->resolve());
    }

    // ----------------------------------------------------------- cost file

    public function test_the_stores_never_count_as_ledger_expenses_of_an_operation(): void
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);

        // Even if a chart ever re-types a store as an expense account, a tagged
        // debit on it is a transfer of value, not a cost of the operation.
        $this->account('1320')->forceFill(['type' => AccountType::Expense])->save();

        $entry = JournalEntry::factory()->posted()->create();
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->account('1320')->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 100,
        ]);
        $this->assertSame(0.0, app(OperationCostService::class)->ledgerExpenses($project));

        // Control: a genuine expense line still counts.
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->account('5010')->id,
            'project_id' => $project->id,
            'direction' => AccountDirection::Debit,
            'amount' => 250,
        ]);
        $this->assertSame(250.0, app(OperationCostService::class)->ledgerExpenses($project));
    }

    // ------------------------------------------------------------ delivery

    public function test_delivery_closing_credits_finished_goods_and_settles_an_unfinished_order_first(): void
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        // Completed without «انتهاء التصنيع» ever being pressed.
        $wo = $this->workOrder($project, ['status' => WorkOrderStatus::Completed]);
        $item = $this->rawItem();
        $this->receive([[$item, 50, 4]]);
        $this->issue($wo, $item, 10, 4);
        $this->assertSame(40.0, $this->balance('1320'));

        $this->deliver($project);

        $closing = $project->costCenterClosings()->firstOrFail();
        $this->assertEqualsWithDelta(40.0, (float) $closing->amount, 0.001);

        $entry = $closing->journalEntry()->with('lines.account')->firstOrFail();
        $this->assertSame('5070', $this->debitLine($entry)->account->code);
        $this->assertSame('1330', $this->creditLine($entry)->account->code);

        // The credit is fully covered: WIP → FG happened first, FG → COGS after.
        $this->assertSame(0.0, $this->balance('1320'));
        $this->assertSame(0.0, $this->balance('1330'));
        $this->assertSame(40.0, $this->balance('5070'));
    }

    public function test_closing_reversal_goes_back_to_the_account_the_closing_credited(): void
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $wo = $this->workOrder($project, ['status' => WorkOrderStatus::Completed]);
        $item = $this->rawItem();
        $this->receive([[$item, 50, 4]]);
        $this->issue($wo, $item, 10, 4);
        $this->deliver($project);

        $closing = $project->costCenterClosings()->firstOrFail();
        $reversal = app(CostCenterClosingService::class)->reverse($closing, null, 'wrong operation');

        $entry = $reversal->journalEntry()->with('lines.account')->firstOrFail();
        $this->assertSame('1330', $this->debitLine($entry)->account->code);
        $this->assertSame('5070', $this->creditLine($entry)->account->code);
        $this->assertSame(40.0, $this->balance('1330'));
        $this->assertSame(0.0, $this->balance('5070'));
    }

    // ---------------------------------------------------------- full chain

    public function test_the_full_chain_purchase_issue_return_loss_finish_deliver(): void
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $raw = $this->rawItem();
        $finished = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $wo = $this->workOrder($project, ['output_item_id' => $finished->id]);
        $wos = app(WorkOrderService::class);

        // Purchase 50 × 4.
        $this->receive([[$raw, 50, 4]]);
        $this->assertSame(200.0, $this->balance('1310'));

        // Issue 10 (40), return 2 (8), write off 1 (4).
        $this->issue($wo, $raw, 10, 4);
        $this->giveBack($wo, $raw, 2, 4);
        $this->writeOff($wo, $raw, 1, 4);
        $this->assertSame(168.0, $this->balance('1310'));
        $this->assertSame(28.0, $this->balance('1320'));
        $this->assertSame(0.0, $this->balance('1330'));

        // Manufacturing finished: WIP → finished goods.
        $wos->finishManufacturing($wo);
        $this->assertSame(0.0, $this->balance('1320'));
        $this->assertSame(28.0, $this->balance('1330'));

        // QA + completion (produces the goods on the stock side only).
        $wos->submitForQa($wo->fresh(), [['produced_quantity' => 8, 'waste_quantity' => 2]]);
        $wos->complete($wo->fresh());
        $this->assertSame(WorkOrderStatus::Completed, $wo->fresh()->status);
        $this->assertSame(28.0, $this->balance('1330'), 'Completion moves no value of its own — the finish already did.');

        // Delivery closes the cost centre into COGS.
        $this->deliver($project);

        $this->assertSame(168.0, $this->balance('1310'));
        $this->assertSame(0.0, $this->balance('1320'));
        $this->assertSame(0.0, $this->balance('1330'));
        $this->assertSame(4.0, $this->balance('5060'));
        $this->assertSame(28.0, $this->balance('5070'));
        $this->assertSame(-200.0, $this->balance('2010'));

        // 1310 + 5060 + 5070 = 168 + 4 + 28 = the 200 owed to the supplier.
        $trial = app(TrialBalanceService::class)->grouped()->get('EGP');
        $this->assertTrue($trial['balanced'], 'The trial balance must stay balanced through the whole chain.');
        $this->assertEqualsWithDelta($trial['total_debit'], $trial['total_credit'], 0.001);

        // The cost file counts the material once: no double cost from the
        // tagged WIP lines or the COGS closing.
        $cost = app(OperationCostService::class)->breakdown($project);
        $this->assertSame(40.0, $cost['materials_cost']);
        $this->assertSame(0.0, $cost['ledger_expenses']);
        $this->assertSame(40.0, $cost['total_cost']);
        $this->assertSame(28.0, $cost['closed_to_cogs']);
        $this->assertSame(0.0, $cost['unclosed_cost']);

        // Five documents booked an entry each — addition, issue, return,
        // write-off, finish — plus the cost-centre closing, which is idempotent
        // through its own closing row and carries no source reference.
        $this->assertSame(5, JournalEntry::whereNotNull('source_type')->count());
        $this->assertSame(6, JournalEntry::count());

        // Every entry is posted and balanced.
        JournalEntry::with('lines')->get()->each(function (JournalEntry $entry): void {
            $this->assertSame(JournalStatus::Posted, $entry->status);
            $this->assertTrue($entry->isBalanced());
        });
    }
}
