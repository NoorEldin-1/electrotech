<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\QualitySheetStatus;
use App\Enums\WorkOrderStatus;
use App\Events\QualitySheetApproved;
use App\Models\InventoryTransaction;
use App\Models\QualitySheet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QualitySheetService;
use App\Services\WorkOrderService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QualitySheetTest extends TestCase
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

    public function test_ensure_for_work_order_creates_draft_with_lines(): void
    {
        $wo = WorkOrder::factory()->create(['title' => 'Panel DKC-PT']);

        $sheet = app(QualitySheetService::class)->ensureForWorkOrder($wo);

        $this->assertSame(QualitySheetStatus::Draft, $sheet->status);
        $this->assertSame($wo->id, $sheet->work_order_id);
        $this->assertStringStartsWith('QS-', $sheet->sheet_number);
        $this->assertGreaterThan(0, $sheet->lines()->count());
        $this->assertSame($this->actor->id, $sheet->created_by);
    }

    public function test_ensure_for_work_order_is_idempotent(): void
    {
        $wo = WorkOrder::factory()->create();

        $first = app(QualitySheetService::class)->ensureForWorkOrder($wo);
        $second = app(QualitySheetService::class)->ensureForWorkOrder($wo->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $wo->qualitySheets()->count());
    }

    public function test_fill_sets_status_and_signature(): void
    {
        $sheet = QualitySheet::factory()->create();

        app(QualitySheetService::class)->fill($sheet, 'All tests passed');

        $sheet->refresh();
        $this->assertSame(QualitySheetStatus::QaFilled, $sheet->status);
        $this->assertSame($this->actor->id, $sheet->qa_filled_by);
        $this->assertNotNull($sheet->qa_filled_at);
        $this->assertSame('All tests passed', $sheet->qa_inspector_notes);
    }

    public function test_cannot_fill_after_approval(): void
    {
        $sheet = QualitySheet::factory()->approved()->create();

        $this->expectException(\RuntimeException::class);
        app(QualitySheetService::class)->fill($sheet);
    }

    public function test_cannot_approve_before_qa_fills(): void
    {
        $sheet = QualitySheet::factory()->create(); // draft

        $this->expectException(\RuntimeException::class);
        app(QualitySheetService::class)->approve($sheet);
    }

    public function test_approve_sets_status_signature_and_dispatches_event(): void
    {
        Event::fake([QualitySheetApproved::class]);
        $sheet = QualitySheet::factory()->qaFilled()->create();

        app(QualitySheetService::class)->approve($sheet);

        $sheet->refresh();
        $this->assertSame(QualitySheetStatus::Approved, $sheet->status);
        $this->assertSame($this->actor->id, $sheet->factory_approved_by);
        $this->assertNotNull($sheet->factory_approved_at);

        Event::assertDispatched(
            QualitySheetApproved::class,
            fn (QualitySheetApproved $e) => $e->qualitySheet->is($sheet),
        );
    }

    public function test_approve_is_idempotent(): void
    {
        $sheet = QualitySheet::factory()->qaFilled()->create();
        app(QualitySheetService::class)->approve($sheet);

        $firstApprovedAt = $sheet->fresh()->factory_approved_at;
        $this->travel(10)->minutes();

        // A retry must not overwrite the original approval stamp.
        app(QualitySheetService::class)->approve($sheet->fresh());

        $this->assertEquals($firstApprovedAt, $sheet->fresh()->factory_approved_at);
    }

    public function test_approval_notifies_department_users_only(): void
    {
        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');
        $finance = User::factory()->create();
        $finance->assignRole('Finance');
        // A user with no department role must NOT be notified.
        $nobody = User::factory()->create();

        $sheet = QualitySheet::factory()->qaFilled()->create();
        app(QualitySheetService::class)->approve($sheet);

        $this->assertSame(1, $factory->fresh()->notifications()->count());
        $this->assertSame(1, $finance->fresh()->notifications()->count());
        $this->assertSame(0, $nobody->fresh()->notifications()->count());
    }

    public function test_finishing_manufacturing_opens_a_quality_sheet_without_touching_inventory(): void
    {
        // approved(): "انتهاء التصنيع" now requires both the PMO release and
        // the QA sign-off before it will fire.
        $wo = WorkOrder::factory()->approved()->create([
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now()->subHours(2),
            'manufacturing_finished_at' => null,
            'actual_material_cost' => 500,
        ]);

        app(WorkOrderService::class)->finishManufacturing($wo);

        $this->assertSame(1, $wo->qualitySheets()->count());
        // The quality sheet is documentation only — no stock or cost movement.
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertEquals(500, (float) $wo->fresh()->actual_material_cost);
    }

    public function test_factory_manager_has_quality_sheet_permissions(): void
    {
        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');

        $this->assertTrue($factory->can('quality_sheets.fill'));
        $this->assertTrue($factory->can('quality_sheets.approve'));
        $this->assertTrue($factory->can('quality_sheets.print'));
    }
}
