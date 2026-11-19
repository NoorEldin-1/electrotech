<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\ItemType;
use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Filament\Pages\InventoryValuation;
use App\Models\Account;
use App\Models\InventoryTransaction;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InventoryValuationService;
use App\Services\JournalEntryService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Inventory valuation report (Final_Modifications Task 8): who can open it,
 * that its ledger numbers are exactly what the posted journals say, that the
 * stock-card side is the moving weighted average, and that the difference and
 * the per-operation work-in-progress breakdown add up.
 *
 * The movements and journals are written directly (not through the voucher
 * services) so this test stays independent of the automatic inventory journals
 * and only exercises the report.
 */
class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;

    private Account $legacy;

    private Account $raw;

    private Account $wip;

    private Account $finished;

    private Account $offset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'operations.inventory_account_code' => '1300',
            'operations.raw_materials_account_code' => '1310',
            'operations.wip_account_code' => '1320',
            'operations.finished_goods_account_code' => '1330',
        ]);

        $this->legacy = $this->account('1300', 'المخزون', AccountType::Asset);
        $this->raw = $this->account('1310', 'مخزن الخامات', AccountType::Asset);
        $this->wip = $this->account('1320', 'مخزن تحت التشغيل', AccountType::Asset);
        $this->finished = $this->account('1330', 'مخزن المنتجات التامة', AccountType::Asset);
        $this->offset = $this->account('2010', 'الموردون', AccountType::Liability);
    }

    // ------------------------------------------------------------ access

    public function test_page_is_gated_by_the_reports_inventory_valuation_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('reports.inventory_valuation');
        $this->actingAs($allowed);
        $this->assertTrue(InventoryValuation::canAccess());

        // Another finance report's permission is not enough.
        $other = User::factory()->create();
        $other->givePermissionTo('trial_balance.view');
        $this->actingAs($other);
        $this->assertFalse(InventoryValuation::canAccess());
    }

    public function test_finance_general_manager_and_admin_hold_the_permission_by_default(): void
    {
        foreach (['Finance', 'General_Manager', 'Admin'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertTrue($user->can('reports.inventory_valuation'), "{$role} should hold it");
        }

        foreach (['Sales', 'Procurement', 'Warehouse_Manager', 'Factory_Manager'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertFalse($user->can('reports.inventory_valuation'), "{$role} must not hold it");
        }
    }

    public function test_grant_migration_gives_the_permission_to_existing_roles_idempotently(): void
    {
        foreach (['Finance', 'General_Manager'] as $name) {
            Role::findByName($name, 'web')->revokePermissionTo('reports.inventory_valuation');
        }

        $migration = require database_path('migrations/2026_11_19_100800_grant_inventory_valuation_permission.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Role::findByName('Finance', 'web')->hasPermissionTo('reports.inventory_valuation'));
        $this->assertTrue(Role::findByName('General_Manager', 'web')->hasPermissionTo('reports.inventory_valuation'));
        $this->assertSame(1, Permission::where('name', 'reports.inventory_valuation')->count());
    }

    public function test_page_renders_for_a_permitted_user_and_shows_the_posted_balances(): void
    {
        $this->postEntry($this->raw, $this->offset, 430000, '2026-01-10');

        $user = User::factory()->create();
        $user->assignRole('Finance');
        $this->actingAs($user);

        Livewire::test(InventoryValuation::class)
            ->assertOk()
            ->assertSee('430,000.00');
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        $this->actingAs($user);

        Livewire::test(InventoryValuation::class)->assertForbidden();
    }

    // ------------------------------------------------------------ ledger

    public function test_ledger_balances_match_the_posted_journals(): void
    {
        $this->postEntry($this->raw, $this->offset, 1000, '2026-01-10');
        $this->postEntry($this->raw, $this->offset, 500, '2026-01-12');
        $this->postEntry($this->wip, $this->raw, 400, '2026-01-20');   // issue: raw -> wip
        $this->postEntry($this->finished, $this->wip, 250, '2026-01-25'); // finish: wip -> finished
        $this->postEntry($this->legacy, $this->offset, 75, '2026-01-05');

        $report = app(InventoryValuationService::class)->build();
        $stores = collect($report['stores'])->keyBy('key');

        $this->assertEqualsWithDelta(1100.0, $stores['raw_materials']['ledger'], 0.001);  // 1500 - 400
        $this->assertEqualsWithDelta(150.0, $stores['work_in_progress']['ledger'], 0.001); // 400 - 250
        $this->assertEqualsWithDelta(250.0, $stores['finished_goods']['ledger'], 0.001);
        $this->assertEqualsWithDelta(75.0, $report['legacy']['balance'], 0.001);

        // Total = the three stores + the old unclassified 1300 balance.
        $this->assertEqualsWithDelta(1575.0, $report['totals']['ledger'], 0.001);
        $this->assertSame([], $report['missing_account_codes']);
    }

    public function test_as_of_date_cuts_the_ledger_off_and_drafts_never_count(): void
    {
        $this->postEntry($this->raw, $this->offset, 1000, '2026-01-10');
        $this->postEntry($this->raw, $this->offset, 500, '2026-03-01');

        // A draft entry is not a balance.
        $draft = JournalEntry::factory()->create(['entry_date' => '2026-01-11']);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $this->raw->id, 'direction' => AccountDirection::Debit, 'amount' => 9999]);
        JournalEntryLine::factory()->create(['journal_entry_id' => $draft->id, 'account_id' => $this->offset->id, 'direction' => AccountDirection::Credit, 'amount' => 9999]);

        $service = app(InventoryValuationService::class);

        $february = collect($service->build(Carbon::parse('2026-02-01'))['stores'])->keyBy('key');
        $this->assertEqualsWithDelta(1000.0, $february['raw_materials']['ledger'], 0.001);

        $all = collect($service->build()['stores'])->keyBy('key');
        $this->assertEqualsWithDelta(1500.0, $all['raw_materials']['ledger'], 0.001);
    }

    public function test_a_store_without_an_account_in_the_chart_is_reported_not_zeroed(): void
    {
        $this->finished->delete();

        $report = app(InventoryValuationService::class)->build();
        $stores = collect($report['stores'])->keyBy('key');

        $this->assertNull($stores['finished_goods']['ledger']);
        $this->assertNull($stores['finished_goods']['difference']);
        $this->assertSame(['1330'], $report['missing_account_codes']);
    }

    // -------------------------------------------------------- stock card

    public function test_stock_card_value_uses_the_moving_average_and_the_difference_is_ledger_minus_stock(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 999]);

        // 10 @ 100, 10 @ 200  -> average 150; issue 4 @ 150 -> 16 left = 2400.
        $this->move($item, TransactionType::In, WarehouseType::RawMaterials, 10, 100, '2026-01-02 09:00:00');
        $this->move($item, TransactionType::In, WarehouseType::RawMaterials, 10, 200, '2026-01-03 09:00:00');
        $this->move($item, TransactionType::Out, WarehouseType::RawMaterials, 4, null, '2026-01-04 09:00:00');

        // The books only journalled the second receipt.
        $this->postEntry($this->raw, $this->offset, 2000, '2026-01-03');

        $stores = collect(app(InventoryValuationService::class)->build()['stores'])->keyBy('key');

        $this->assertEqualsWithDelta(2400.0, $stores['raw_materials']['stock_card'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $stores['raw_materials']['ledger'], 0.001);
        $this->assertEqualsWithDelta(-400.0, $stores['raw_materials']['difference'], 0.001);
        $this->assertFalse($stores['raw_materials']['reconciled']);
    }

    public function test_stock_card_is_cut_off_at_the_as_of_date(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        $this->move($item, TransactionType::In, WarehouseType::RawMaterials, 10, 100, '2026-01-02 09:00:00');
        $this->move($item, TransactionType::In, WarehouseType::RawMaterials, 10, 100, '2026-03-02 09:00:00');

        $service = app(InventoryValuationService::class);

        $this->assertEqualsWithDelta(
            1000.0,
            collect($service->build(Carbon::parse('2026-02-01'))['stores'])->keyBy('key')['raw_materials']['stock_card'],
            0.001,
        );
        $this->assertEqualsWithDelta(
            2000.0,
            collect($service->build(Carbon::parse('2026-03-02'))['stores'])->keyBy('key')['raw_materials']['stock_card'],
            0.001,
        );
    }

    public function test_matching_books_and_stock_report_no_difference(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        $this->move($item, TransactionType::In, WarehouseType::RawMaterials, 5, 80, '2026-01-02 09:00:00');
        $this->postEntry($this->raw, $this->offset, 400, '2026-01-02');

        $report = app(InventoryValuationService::class)->build();

        $this->assertEqualsWithDelta(0.0, $report['totals']['difference'], 0.001);
        $this->assertTrue($report['totals']['reconciled']);
    }

    // --------------------------------------------------- WIP by operation

    public function test_work_in_progress_is_broken_down_per_operation(): void
    {
        $alpha = Project::factory()->create(['code' => 'P-1', 'name' => 'Alpha']);
        $beta = Project::factory()->create(['code' => 'P-2', 'name' => 'Beta']);

        $alphaOrder = WorkOrder::factory()->create(['project_id' => $alpha->id]);
        $betaOrder = WorkOrder::factory()->create(['project_id' => $beta->id]);

        $alphaIssue = IssueVoucher::factory()->posted()->create(['work_order_id' => $alphaOrder->id]);
        $betaIssue = IssueVoucher::factory()->posted()->create(['work_order_id' => $betaOrder->id]);

        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        // Alpha: 5 @ 20 into WIP, then 2 consumed by its work order at the
        // average -> 60 left. Beta: 3 @ 10 -> 30.
        $this->move($item, TransactionType::In, WarehouseType::WorkInProgress, 5, 20, '2026-01-02 09:00:00', $alphaIssue);
        $this->move($item, TransactionType::Out, WarehouseType::WorkInProgress, 2, null, '2026-01-03 09:00:00', $alphaOrder);
        $this->move($item, TransactionType::In, WarehouseType::WorkInProgress, 3, 10, '2026-01-04 09:00:00', $betaIssue);

        // Ledger: 1320 lines tagged per operation, plus one untagged line.
        $this->postEntry($this->wip, $this->raw, 100, '2026-01-02', $alpha->id);
        $this->postEntry($this->wip, $this->raw, 30, '2026-01-04', $beta->id);
        $this->postEntry($this->wip, $this->raw, 7, '2026-01-05');

        $report = app(InventoryValuationService::class)->build();
        $rows = collect($report['wip_operations']);

        $alphaRow = $rows->first(fn (array $row): bool => ($row['project']['code'] ?? null) === 'P-1');
        $betaRow = $rows->first(fn (array $row): bool => ($row['project']['code'] ?? null) === 'P-2');
        $unallocated = $rows->first(fn (array $row): bool => $row['project'] === null);

        $this->assertEqualsWithDelta(100.0, $alphaRow['ledger'], 0.001);
        $this->assertEqualsWithDelta(60.0, $alphaRow['stock_card'], 0.001);
        $this->assertEqualsWithDelta(40.0, $alphaRow['difference'], 0.001);

        $this->assertEqualsWithDelta(30.0, $betaRow['ledger'], 0.001);
        $this->assertEqualsWithDelta(30.0, $betaRow['stock_card'], 0.001);
        $this->assertEqualsWithDelta(0.0, $betaRow['difference'], 0.001);

        $this->assertEqualsWithDelta(7.0, $unallocated['ledger'], 0.001);
        $this->assertEqualsWithDelta(0.0, $unallocated['stock_card'], 0.001);

        // The per-operation column totals equal the WIP store totals.
        $store = collect($report['stores'])->keyBy('key')['work_in_progress'];
        $this->assertEqualsWithDelta($store['ledger'], $rows->sum('ledger'), 0.001);
        $this->assertEqualsWithDelta($store['stock_card'], $rows->sum('stock_card'), 0.001);
    }

    // ----------------------------------------------------------- helpers

    private function account(string $code, string $name, AccountType $type): Account
    {
        return Account::factory()->ofType($type)->create([
            'code' => $code,
            'name' => $name,
            'currency' => 'EGP',
        ]);
    }

    /**
     * Post a balanced two-line entry; the debit line optionally carries the
     * operation (cost centre) tag.
     */
    private function postEntry(Account $debit, Account $credit, float $amount, string $date, ?int $projectId = null): void
    {
        $entry = JournalEntry::factory()->create(['entry_date' => $date]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debit->id,
            'project_id' => $projectId,
            'direction' => AccountDirection::Debit,
            'amount' => $amount,
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $credit->id,
            'direction' => AccountDirection::Credit,
            'amount' => $amount,
        ]);

        app(JournalEntryService::class)->post($entry->fresh('lines'));
    }

    private function move(
        Item $item,
        TransactionType $type,
        WarehouseType $warehouse,
        float $quantity,
        ?float $unitCost,
        string $when,
        ?object $reference = null,
    ): void {
        InventoryTransaction::factory()->create([
            'item_id' => $item->id,
            'type' => $type,
            'warehouse_type' => $warehouse,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'performed_by' => User::factory()->create()->id,
            'created_at' => $when,
        ]);
    }
}
