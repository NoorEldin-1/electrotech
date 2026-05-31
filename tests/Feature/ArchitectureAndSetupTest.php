<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchitectureAndSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_factories_can_successfully_generate_realistic_data(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $project = Project::factory()->create();
        $this->assertDatabaseHas('projects', ['id' => $project->id]);

        $item = Item::factory()->create();
        $this->assertDatabaseHas('items', ['id' => $item->id]);

        $bom = Bom::factory()->create();
        $this->assertDatabaseHas('boms', ['id' => $bom->id]);

        $bomItem = BomItem::factory()->create();
        $this->assertDatabaseHas('bom_items', ['id' => $bomItem->id]);

        $workOrder = WorkOrder::factory()->create();
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id]);

        $purchaseOrder = PurchaseOrder::factory()->create();
        $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrder->id]);

        $purchaseOrderItem = PurchaseOrderItem::factory()->create();
        $this->assertDatabaseHas('purchase_order_items', ['id' => $purchaseOrderItem->id]);

        $inventory = Inventory::factory()->create();
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id]);

        $inventoryTransaction = InventoryTransaction::factory()->create();
        $this->assertDatabaseHas('inventory_transactions', ['id' => $inventoryTransaction->id]);

        $attachment = Attachment::factory()->create();
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);

        $offer = ProjectOffer::factory()->create();
        $this->assertDatabaseHas('project_offers', ['id' => $offer->id]);
    }
}
