<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use App\Services\IssueVoucherService;
use App\Services\WorkOrderService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array{0: WorkOrder, 1: Item, 2: Project}
     */
    private function makeWorkOrderWithBom(float $bomQty = 10): array
    {
        $project = Project::factory()->create(['actual_cost' => 0]);
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $bom = Bom::factory()->create(['project_id' => $project->id]);
        BomItem::factory()->create([
            'bom_id' => $bom->id,
            'item_id' => $item->id,
            'quantity' => $bomQty,
            'waste_percentage' => 0,
        ]);
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'bom_id' => $bom->id]);

        return [$wo, $item, $project];
    }

    public function test_create_from_work_order_builds_draft_with_bom_lines(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithBom(10);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);

        $this->assertSame(VoucherStatus::Draft, $voucher->status);
        $this->assertCount(1, $voucher->lines);
        $this->assertEquals(10, (float) $voucher->lines->first()->quantity);
        $this->assertEquals($item->id, $voucher->lines->first()->item_id);
    }

    public function test_work_order_service_issue_materials_returns_draft_voucher(): void
    {
        [$wo] = $this->makeWorkOrderWithBom();

        $voucher = app(WorkOrderService::class)->issueMaterials($wo);

        $this->assertSame(VoucherStatus::Draft, $voucher->status);
        $this->assertEquals($wo->id, $voucher->work_order_id);
    }

    public function test_posting_transfers_raw_to_wip_and_loads_cost_onto_project(): void
    {
        [$wo, $item, $project] = $this->makeWorkOrderWithBom(10);
        app(InventoryService::class)->addStock($item, 50, null, null, WarehouseType::RawMaterials);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        app(IssueVoucherService::class)->post($voucher);

        $fresh = $item->fresh();
        $this->assertEquals(40, $fresh->quantityIn(WarehouseType::RawMaterials));
        $this->assertEquals(10, $fresh->quantityIn(WarehouseType::WorkInProgress));

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Posted, $voucher->status);
        $this->assertEquals(40, (float) $voucher->total_value); // 10 * 4

        // Cost loaded onto the operation (project = cost centre).
        $this->assertEquals(40, (float) $project->fresh()->actual_cost);
    }

    public function test_posting_fails_when_raw_stock_is_short(): void
    {
        [$wo] = $this->makeWorkOrderWithBom(10);
        // No raw stock added.

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);

        $this->expectException(\RuntimeException::class);
        app(IssueVoucherService::class)->post($voucher);
    }

    public function test_rbac_factory_creates_warehouse_posts(): void
    {
        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');
        $this->assertTrue($factory->can('issue_vouchers.create'));

        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');
        $this->assertTrue($warehouse->can('issue_vouchers.post'));
    }
}
