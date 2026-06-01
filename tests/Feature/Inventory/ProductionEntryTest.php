<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemType;
use App\Enums\WarehouseType;
use App\Enums\WorkOrderStatus;
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

class ProductionEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_completing_work_order_produces_finished_goods_consumes_wip_and_records_loss(): void
    {
        $wos = app(WorkOrderService::class);
        $inventory = app(InventoryService::class);

        $project = Project::factory()->create();
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $finished = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $inventory->addStock($raw, 50, null, null, WarehouseType::RawMaterials);

        $bom = Bom::factory()->create(['project_id' => $project->id]);
        BomItem::factory()->create(['bom_id' => $bom->id, 'item_id' => $raw->id, 'quantity' => 10, 'waste_percentage' => 0]);

        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'bom_id' => $bom->id,
            'output_item_id' => $finished->id,
            'status' => WorkOrderStatus::Pending,
            'planned_quantity' => 10,
            'produced_quantity' => 0,
            'waste_quantity' => 0,
        ]);

        // Drive the real lifecycle: start → issue (to WIP) → QA → complete.
        $wos->start($wo);

        $voucher = $wos->issueMaterials($wo);
        app(IssueVoucherService::class)->post($voucher);
        $this->assertEquals(10, $raw->fresh()->quantityIn(WarehouseType::WorkInProgress));

        $wos->submitForQa($wo, 8, 2);
        $wos->approveQa($wo);
        $wos->complete($wo);

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Completed, $wo->status);

        // Finished goods produced.
        $this->assertEquals(8, $finished->fresh()->quantityIn(WarehouseType::FinishedGoods));

        // WIP materials consumed back to zero, raw untouched at 40.
        $this->assertEquals(0, $raw->fresh()->quantityIn(WarehouseType::WorkInProgress));
        $this->assertEquals(40, $raw->fresh()->quantityIn(WarehouseType::RawMaterials));

        // Production entry recorded the planned-vs-actual loss.
        $entry = $wo->productionEntries()->first();
        $this->assertNotNull($entry);
        $this->assertEquals(10, (float) $entry->planned_quantity);
        $this->assertEquals(8, (float) $entry->produced_quantity);
        $this->assertEquals(2, (float) $entry->scrap_quantity);
    }

    public function test_cannot_complete_without_qa_approval(): void
    {
        $wos = app(WorkOrderService::class);
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::QaReview]);

        $this->expectException(\RuntimeException::class);
        $wos->complete($wo);
    }

    public function test_factory_manager_can_view_scrap(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Factory_Manager');

        $this->assertTrue($user->can('production_entries.view'));
        $this->assertTrue($user->can('scrap.view'));
    }
}
