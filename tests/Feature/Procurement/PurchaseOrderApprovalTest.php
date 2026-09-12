<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Slides 1 & 5: the technical-office manager approves a draft PO, which then
 * becomes "sent" (مُرسَل) with the approver and timestamp recorded.
 */
class PurchaseOrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_technical_office_can_approve_a_draft_po(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Technical_Office');
        $this->actingAs($manager);

        $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Draft]);

        // Approval now requires at least one line item: an order that commits
        // to nothing can never be completed by a receipt, so it would sit in
        // "sent" forever. The rule moved into PurchaseOrderService so the API
        // and the panel share it; this fixture gains the line the test always
        // implied it had.
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'quantity' => 10,
            'unit_price' => 100,
            'received_quantity' => 0,
        ]);

        Livewire::test(ListPurchaseOrders::class)
            ->callTableAction('approve', $po);

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Submitted, $po->status);
        $this->assertSame($manager->id, $po->approved_by);
        $this->assertNotNull($po->approved_at);
    }

    public function test_approve_is_hidden_for_non_draft(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Technical_Office');
        $this->actingAs($manager);

        $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Submitted]);

        Livewire::test(ListPurchaseOrders::class)
            ->assertTableActionHidden('approve', $po);
    }

    public function test_role_without_approve_permission_cannot_approve(): void
    {
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');

        $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Draft]);

        $this->assertFalse($warehouse->can('approve', $po));
    }
}
