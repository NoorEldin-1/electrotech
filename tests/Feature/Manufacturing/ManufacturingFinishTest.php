<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\WorkOrderStatus;
use App\Events\ManufacturingFinished;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ManufacturingFinishTest extends TestCase
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

    private function startedWorkOrder(int $startedMinutesAgo = 125): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now()->subMinutes($startedMinutesAgo),
            'actual_end_date' => null,
            'manufacturing_finished_at' => null,
            'actual_material_cost' => 500,
        ]);
    }

    public function test_finish_records_time_duration_and_actor(): void
    {
        $wo = $this->startedWorkOrder(125);

        app(WorkOrderService::class)->finishManufacturing($wo);

        $wo->refresh();
        $this->assertNotNull($wo->manufacturing_finished_at);
        $this->assertSame($this->actor->id, $wo->manufacturing_finished_by);
        $this->assertEqualsWithDelta(125, $wo->manufacturing_duration_minutes, 1);
        $this->assertTrue($wo->isManufacturingFinished());
    }

    public function test_finish_is_idempotent(): void
    {
        $wo = $this->startedWorkOrder(60);
        app(WorkOrderService::class)->finishManufacturing($wo);

        $firstFinishedAt = $wo->fresh()->manufacturing_finished_at;
        $this->travel(10)->minutes();

        // A retry must not overwrite the original finish stamp.
        app(WorkOrderService::class)->finishManufacturing($wo->fresh());

        $this->assertEquals($firstFinishedAt, $wo->fresh()->manufacturing_finished_at);
    }

    public function test_cannot_finish_a_work_order_that_never_started(): void
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::Pending,
            'actual_start_date' => null,
            'manufacturing_finished_at' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        app(WorkOrderService::class)->finishManufacturing($wo);
    }

    public function test_finish_works_during_qa_review(): void
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::QaReview,
            'actual_start_date' => now()->subHours(2),
            'manufacturing_finished_at' => null,
        ]);

        app(WorkOrderService::class)->finishManufacturing($wo);

        $this->assertTrue($wo->fresh()->isManufacturingFinished());
        // Status is untouched — finish is orthogonal to the QA state machine.
        $this->assertSame(WorkOrderStatus::QaReview, $wo->fresh()->status);
    }

    public function test_finish_dispatches_the_event(): void
    {
        Event::fake([ManufacturingFinished::class]);
        $wo = $this->startedWorkOrder();

        app(WorkOrderService::class)->finishManufacturing($wo);

        Event::assertDispatched(
            ManufacturingFinished::class,
            fn (ManufacturingFinished $e) => $e->workOrder->is($wo),
        );
    }

    public function test_finish_notifies_department_users_only(): void
    {
        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');
        $finance = User::factory()->create();
        $finance->assignRole('Finance');
        // A user with no department role must NOT be notified.
        $nobody = User::factory()->create();

        app(WorkOrderService::class)->finishManufacturing($this->startedWorkOrder());

        $this->assertSame(1, $factory->fresh()->notifications()->count());
        $this->assertSame(1, $finance->fresh()->notifications()->count());
        $this->assertSame(0, $nobody->fresh()->notifications()->count());
    }

    public function test_finish_touches_neither_inventory_nor_cost(): void
    {
        $wo = $this->startedWorkOrder();

        app(WorkOrderService::class)->finishManufacturing($wo);

        $this->assertSame(0, InventoryTransaction::count());
        // The financial close (actual_material_cost / actual_end_date) stays
        // with complete(), behind the QA gate.
        $this->assertEquals(500, (float) $wo->fresh()->actual_material_cost);
        $this->assertNull($wo->fresh()->actual_end_date);
    }

    public function test_duration_human_accessor_formats(): void
    {
        $wo = $this->startedWorkOrder(125);
        app(WorkOrderService::class)->finishManufacturing($wo);

        $human = $wo->fresh()->manufacturing_duration_human;
        $this->assertNotNull($human);
        $this->assertStringContainsString('2', $human); // 2 hours ...
    }

    public function test_factory_manager_has_finish_permission(): void
    {
        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');

        $this->assertTrue($factory->can('work_orders.finish_manufacturing'));
    }
}
