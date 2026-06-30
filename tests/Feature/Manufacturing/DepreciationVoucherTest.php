<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\AccountDirection;
use App\Enums\ItemType;
use App\Enums\JournalStatus;
use App\Enums\LossType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\DepreciationVoucher;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DepreciationVoucherService;
use App\Services\InventoryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepreciationVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * A work order whose material is in work-in-progress, with the matching
     * value loaded onto the operation (project + WO).
     *
     * @return array{0: WorkOrder, 1: Item, 2: Project}
     */
    private function makeWorkOrderWithWipStock(float $wipQty = 10, float $unitCost = 4): array
    {
        $value = $wipQty * $unitCost;
        $project = Project::factory()->create(['actual_cost' => $value]);
        $item = Item::factory()->create([
            'type' => ItemType::RawMaterial,
            'unit_cost' => $unitCost,
            'is_scrap' => false,
        ]);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'actual_material_cost' => $value,
        ]);

        app(InventoryService::class)->addStock($item, $wipQty, null, null, WarehouseType::WorkInProgress);

        return [$wo, $item, $project];
    }

    private function makeVoucher(WorkOrder $wo, Item $item, float $qty, LossType $type, float $unitCost = 4): DepreciationVoucher
    {
        $voucher = DepreciationVoucher::factory()->create([
            'work_order_id' => $wo->id,
            'loss_type' => $type,
            'status' => VoucherStatus::Draft,
        ]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $unitCost]);

        return $voucher->load('lines.item');
    }

    public function test_abnormal_loss_deducts_wip_reverses_cost_and_posts_loss_journal(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$wo, $item, $project] = $this->makeWorkOrderWithWipStock(10, 4);
        $voucher = $this->makeVoucher($wo, $item, 3, LossType::Abnormal, 4);

        app(DepreciationVoucherService::class)->post($voucher);

        // Loss left WIP — item-card value drops with it.
        $item->refresh();
        $this->assertEquals(7, $item->quantityIn(WarehouseType::WorkInProgress));

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Posted, $voucher->status);
        $this->assertEquals(12, (float) $voucher->total_value); // 3 * 4

        // Abnormal loss is removed from the operation.
        $this->assertEquals(28, (float) $project->fresh()->actual_cost);
        $this->assertEquals(28, (float) $wo->fresh()->actual_material_cost);

        // Balanced, posted journal: Dr 5060 (loss) / Cr 1300 (inventory), untagged.
        $entry = $voucher->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertTrue($entry->isBalanced());

        $debit = $entry->lines->firstWhere('direction', AccountDirection::Debit);
        $credit = $entry->lines->firstWhere('direction', AccountDirection::Credit);
        $this->assertSame('5060', $debit->account->code);
        $this->assertSame('1300', $credit->account->code);
        $this->assertEquals(12, (float) $debit->amount);
        $this->assertNull($debit->project_id); // not tagged → no double count
        $this->assertNull($credit->project_id);
    }

    public function test_natural_loss_keeps_cost_on_operation_and_books_operating_expense(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$wo, $item, $project] = $this->makeWorkOrderWithWipStock(10, 4);
        $voucher = $this->makeVoucher($wo, $item, 2, LossType::Natural, 4);

        app(DepreciationVoucherService::class)->post($voucher);

        $this->assertEquals(8, $item->fresh()->quantityIn(WarehouseType::WorkInProgress));

        // Natural loss stays loaded on the operation (no reversal).
        $this->assertEquals(40, (float) $project->fresh()->actual_cost);
        $this->assertEquals(40, (float) $wo->fresh()->actual_material_cost);

        // Journal: Dr 5010 (operating expenses) / Cr 1300 (inventory).
        $entry = $voucher->fresh()->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertSame('5010', $entry->lines->firstWhere('direction', AccountDirection::Debit)->account->code);
        $this->assertSame('1300', $entry->lines->firstWhere('direction', AccountDirection::Credit)->account->code);
    }

    public function test_posting_without_loss_accounts_skips_journal_but_moves_stock(): void
    {
        // Chart NOT seeded — accounts unresolved.
        [$wo, $item] = $this->makeWorkOrderWithWipStock(10, 4);
        $voucher = $this->makeVoucher($wo, $item, 3, LossType::Abnormal, 4);

        app(DepreciationVoucherService::class)->post($voucher);

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Posted, $voucher->status);
        $this->assertNull($voucher->journal_entry_id); // graceful skip
        $this->assertEquals(7, $item->fresh()->quantityIn(WarehouseType::WorkInProgress));
    }

    public function test_zero_quantity_lines_block_an_empty_post(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock();
        $voucher = $this->makeVoucher($wo, $item, 0, LossType::Abnormal);

        $this->expectException(\RuntimeException::class);
        app(DepreciationVoucherService::class)->post($voucher);
    }

    public function test_posting_twice_throws(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$wo, $item] = $this->makeWorkOrderWithWipStock(10, 4);
        $voucher = $this->makeVoucher($wo, $item, 2, LossType::Abnormal, 4);
        app(DepreciationVoucherService::class)->post($voucher);

        $this->expectException(\RuntimeException::class);
        app(DepreciationVoucherService::class)->post($voucher->fresh());
    }

    public function test_posting_fails_when_wip_stock_is_short(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock(2, 4);
        $voucher = $this->makeVoucher($wo, $item, 5, LossType::Abnormal, 4);

        $this->expectException(\RuntimeException::class);
        app(DepreciationVoucherService::class)->post($voucher);
    }

    public function test_create_from_work_order_prefills_issued_items_at_zero(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock();
        $iv = IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id]);
        $iv->lines()->create(['item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 4]);

        $voucher = app(DepreciationVoucherService::class)->createFromWorkOrder($wo->fresh());

        $this->assertSame(VoucherStatus::Draft, $voucher->status);
        $this->assertSame(LossType::Abnormal, $voucher->loss_type);
        $this->assertCount(1, $voucher->lines);
        $this->assertEquals($item->id, $voucher->lines->first()->item_id);
        $this->assertEquals(0, (float) $voucher->lines->first()->quantity);
    }

    public function test_rbac_warehouse_and_factory_roles_can_handle_write_offs(): void
    {
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');
        $this->assertTrue($warehouse->can('depreciation_vouchers.post'));

        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');
        $this->assertTrue($factory->can('depreciation_vouchers.create'));
    }
}
