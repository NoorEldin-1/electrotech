<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Models\PurchaseOrder;
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
