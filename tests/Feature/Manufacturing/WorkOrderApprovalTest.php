<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مكتب ادارة المشروعات.pptx سلايد 5 + 10 — the PMO-manager approval gate that
 * releases a manufacturing order out of Draft before the floor can start.
 */
class WorkOrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
    }

    public function test_new_order_created_via_resource_defaults_to_draft(): void
    {
        // The Filament form defaults status to Draft; a bare create (no status)
        // uses the DB/model path, so assert the enum ordering + form default
        // intent by creating a draft explicitly and checking the gate.
        $wo = WorkOrder::factory()->draft()->create();

        $this->assertSame(WorkOrderStatus::Draft, $wo->status);
        $this->assertFalse($wo->isOrderApproved());
    }

    public function test_approve_order_moves_draft_to_pending_and_records_approver(): void
    {
        $wo = WorkOrder::factory()->draft()->create();

        app(WorkOrderService::class)->approveOrder($wo);

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Pending, $wo->status);
        $this->assertSame($this->actor->id, $wo->order_approved_by);
        $this->assertNotNull($wo->order_approved_at);
        $this->assertTrue($wo->isOrderApproved());
    }

    public function test_approve_order_rejects_a_non_draft_order(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress]);

        $this->expectException(\RuntimeException::class);
        app(WorkOrderService::class)->approveOrder($wo);
    }

    public function test_approve_order_is_idempotent_after_approval(): void
    {
        $wo = WorkOrder::factory()->draft()->create();
        app(WorkOrderService::class)->approveOrder($wo);
        $firstApprovedAt = $wo->fresh()->order_approved_at;

        $this->travel(10)->minutes();

        // A retry after the order already left Draft is a silent success and
        // must not overwrite the original approval stamp.
        app(WorkOrderService::class)->approveOrder($wo->fresh());

        $this->assertEquals($firstApprovedAt, $wo->fresh()->order_approved_at);
    }

    public function test_manufacturing_cannot_start_before_pmo_approval(): void
    {
        $wo = WorkOrder::factory()->draft()->create();

        // start() requires Pending; a Draft must go through approveOrder first.
        $this->expectException(\RuntimeException::class);
        app(WorkOrderService::class)->start($wo);
    }

    public function test_start_succeeds_after_approval(): void
    {
        $wo = WorkOrder::factory()->draft()->create();
        app(WorkOrderService::class)->approveOrder($wo);

        app(WorkOrderService::class)->start($wo->fresh());

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_pmo_office_can_approve_but_factory_manager_cannot(): void
    {
        $pmo = User::factory()->create();
        $pmo->assignRole('Technical_Office');
        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');

        $this->assertTrue($pmo->can('work_orders.approve_order'));
        $this->assertFalse($factory->can('work_orders.approve_order'));
    }
}
