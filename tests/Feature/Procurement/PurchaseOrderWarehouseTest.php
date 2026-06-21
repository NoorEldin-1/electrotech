<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\ItemType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WarehouseType;
use App\Models\AccountEntry;
use App\Models\Item;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Procurement feedback: a purchase order need not belong to an operation.
 * Leaving the project empty makes it a warehouse/stock purchase (مربوط بالمخازن).
 * Such orders receive into the item's home warehouse exactly like any other —
 * the stock/ledger flow never depended on the project.
 */
class PurchaseOrderWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_purchase_order_can_be_created_without_a_project(): void
    {
        $po = PurchaseOrder::factory()->warehouse()->create();

        $this->assertNull($po->fresh()->project_id);
        $this->assertNull($po->project);
    }

    public function test_warehouse_po_receives_into_stock_and_credits_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $po = PurchaseOrder::factory()->warehouse()->create([
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => PurchaseOrderStatus::Submitted,
        ]);
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $poItem = $po->items()->create(['item_id' => $item->id, 'quantity' => 8, 'unit_price' => 15]);

        $voucher = app(PurchaseOrderService::class)->receiveItems($po->fresh(), [$poItem->id => 8]);

        // Stock lands in the item's home warehouse — independent of any project.
        $this->assertEquals(8, $item->fresh()->quantityIn(WarehouseType::RawMaterials));

        // PO closed by comparison and the linked voucher posted.
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Received, $po->status);
        $this->assertSame($po->id, $voucher->purchase_order_id);

        // Supplier credited (8 × 15) with a null operation name (no project).
        $this->assertEquals(120, $supplier->fresh()->balance);
        $entry = AccountEntry::where('party_id', $supplier->id)->firstOrFail();
        $this->assertNull($entry->operation_name);
    }

    public function test_force_deleting_a_project_detaches_its_orders_as_warehouse_pos(): void
    {
        $project = Project::factory()->create();
        $po = PurchaseOrder::factory()->create(['project_id' => $project->id]);

        $project->forceDelete();

        // nullOnDelete: the order survives as a warehouse PO instead of cascading.
        $po->refresh();
        $this->assertTrue($po->exists);
        $this->assertNull($po->project_id);
    }
}
