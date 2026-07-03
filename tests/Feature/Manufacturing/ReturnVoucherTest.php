<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\ItemType;
use App\Enums\TransactionType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\Project;
use App\Models\ReturnVoucher;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use App\Services\ReturnVoucherService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * A work order whose material is already in work-in-progress, with the
     * matching value loaded onto the operation (project + WO).
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

    private function makeReturnVoucher(WorkOrder $wo, Item $item, float $qty, float $unitCost = 4): ReturnVoucher
    {
        $voucher = ReturnVoucher::factory()->create([
            'work_order_id' => $wo->id,
            'status' => VoucherStatus::Draft,
        ]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $unitCost]);

        return $voucher->load('lines.item');
    }

    public function test_posting_returns_material_to_same_item_raw_stock_and_reverses_cost(): void
    {
        [$wo, $item, $project] = $this->makeWorkOrderWithWipStock(10, 4);
        $voucher = $this->makeReturnVoucher($wo, $item, 3, 4);

        app(ReturnVoucherService::class)->post($voucher);

        $item->refresh();
        // Material left the item's WIP balance...
        $this->assertEquals(7, $item->quantityIn(WarehouseType::WorkInProgress));
        // ...and came back into the SAME item's raw stock (no scrap variant).
        $this->assertEquals(3, $item->quantityIn(WarehouseType::RawMaterials));
        $this->assertNull($item->scrapVariant);
        $this->assertSame(0, Item::where('is_scrap', true)->count());

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Posted, $voucher->status);
        $this->assertEquals(12, (float) $voucher->total_value); // 3 * 4

        // Value reversed off the operation (40 - 12 = 28) — returned material
        // is not loaded on it.
        $this->assertEquals(28, (float) $project->fresh()->actual_cost);
        $this->assertEquals(28, (float) $wo->fresh()->actual_material_cost);
    }

    public function test_return_is_incoming_on_the_raw_stock_card_and_outgoing_on_wip(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock(10, 4);
        app(ReturnVoucherService::class)->post($this->makeReturnVoucher($wo, $item, 5, 4));

        // The وارد leg lands on the item's raw warehouse (In).
        $rawIn = $item->inventoryTransactions()
            ->where('warehouse_type', WarehouseType::RawMaterials->value)
            ->where('type', TransactionType::In->value)
            ->exists();
        $this->assertTrue($rawIn);

        // The صادر leg leaves work-in-progress (Out).
        $wipOut = $item->inventoryTransactions()
            ->where('warehouse_type', WarehouseType::WorkInProgress->value)
            ->where('type', TransactionType::Out->value)
            ->exists();
        $this->assertTrue($wipOut);

        // The returned material is now re-issuable raw stock of the same item.
        $this->assertEquals(5, $item->fresh()->quantityIn(WarehouseType::RawMaterials));
        $this->assertEquals(20, $item->fresh()->valueIn(WarehouseType::RawMaterials)); // 5 * 4
    }

    public function test_zero_quantity_lines_block_an_empty_post(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock();
        $voucher = $this->makeReturnVoucher($wo, $item, 0);

        $this->expectException(\RuntimeException::class);
        app(ReturnVoucherService::class)->post($voucher);
    }

    public function test_posting_twice_throws(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock(10, 4);
        $voucher = $this->makeReturnVoucher($wo, $item, 2, 4);
        app(ReturnVoucherService::class)->post($voucher);

        $this->expectException(\RuntimeException::class);
        app(ReturnVoucherService::class)->post($voucher->fresh());
    }

    public function test_posting_fails_when_wip_stock_is_short(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock(2, 4);
        $voucher = $this->makeReturnVoucher($wo, $item, 5, 4);

        $this->expectException(\RuntimeException::class);
        app(ReturnVoucherService::class)->post($voucher);
    }

    public function test_create_from_work_order_prefills_issued_items_at_zero(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithWipStock();
        $iv = IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id]);
        $iv->lines()->create(['item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 4]);

        $voucher = app(ReturnVoucherService::class)->createFromWorkOrder($wo->fresh());

        $this->assertSame(VoucherStatus::Draft, $voucher->status);
        $this->assertCount(1, $voucher->lines);
        $this->assertEquals($item->id, $voucher->lines->first()->item_id);
        $this->assertEquals(0, (float) $voucher->lines->first()->quantity);
    }

    public function test_rbac_warehouse_and_factory_roles_can_handle_returns(): void
    {
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');
        $this->assertTrue($warehouse->can('return_vouchers.post'));

        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');
        $this->assertTrue($factory->can('return_vouchers.create'));
    }
}
