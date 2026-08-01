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
 * E2E report §3.1 — WO-202607-0002 sat in "Completed" with produced = 12,
 * planned = 0 and no planned dates. The plan is the denominator of every
 * figure the order reports (efficiency, waste %, cost variance), so an order
 * must not be able to reach the factory floor — and therefore completion —
 * without one.
 */
class WorkOrderPlanIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_approve_order_rejects_a_zero_planned_quantity(): void
    {
        $wo = WorkOrder::factory()->draft()->create(['planned_quantity' => 0]);

        $this->expectException(\RuntimeException::class);

        app(WorkOrderService::class)->approveOrder($wo);
    }

    public function test_approve_order_rejects_missing_planned_dates(): void
    {
        $wo = WorkOrder::factory()->draft()->create([
            'planned_start_date' => null,
            'planned_end_date' => null,
        ]);

        try {
            app(WorkOrderService::class)->approveOrder($wo);
            $this->fail('An order with no planned dates should not be approvable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($wo->wo_number, $e->getMessage());
        }

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Draft, $wo->status);
        $this->assertFalse($wo->isOrderApproved());
    }

    public function test_approve_order_accepts_a_complete_plan(): void
    {
        $wo = WorkOrder::factory()->draft()->create([
            'planned_quantity' => 25,
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addWeek()->toDateString(),
        ]);

        app(WorkOrderService::class)->approveOrder($wo);

        $this->assertSame(WorkOrderStatus::Pending, $wo->refresh()->status);
    }

    /**
     * The second gate: a Pending order that never went through approveOrder
     * (legacy row, direct status edit) is still stopped at start().
     */
    public function test_start_rejects_an_order_with_an_incomplete_plan(): void
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::Pending,
            'planned_quantity' => 0,
        ]);

        $this->expectException(\RuntimeException::class);

        app(WorkOrderService::class)->start($wo);
    }

    public function test_start_accepts_an_order_with_a_complete_plan(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Pending]);

        app(WorkOrderService::class)->start($wo);

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::InProgress, $wo->status);
        $this->assertNotNull($wo->actual_start_date);
    }
}
