<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\ProjectStatus;
use App\Enums\VoucherStatus;
use App\Models\DeliveryVoucher;
use App\Models\Installation;
use App\Models\IssueVoucher;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\OperationTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ماليات.pptx سلايد 7 — "يجب عمل مراكز تكلفة + خط زمنى لكل مشروع بالعميل
 * (العملية في المصنع، او العملية تم تركيبها، او العملية مازلت فى المكتب الفنى
 * لانشاء امر التشغيل، وهكذا)".
 *
 * The timeline is derived, never stored, so these tests create the underlying
 * documents and check the stage follows on its own.
 */
class OperationTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function stage(Project $project, string $key): array
    {
        return app(OperationTimelineService::class)
            ->for($project)
            ->firstWhere('key', $key);
    }

    public function test_an_operation_with_no_work_order_is_still_at_the_technical_office_stage(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $this->assertFalse($this->stage($project, 'technical_office')['reached']);
        $this->assertFalse($this->stage($project, 'in_factory')['reached']);
        $this->assertSame('activated', app(OperationTimelineService::class)->currentStage($project)['key']);
    }

    public function test_creating_a_work_order_moves_the_operation_to_the_technical_office(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        WorkOrder::factory()->create(['project_id' => $project->id]);

        $stage = $this->stage($project, 'technical_office');

        $this->assertTrue($stage['reached']);
        $this->assertSame('technical_office', app(OperationTimelineService::class)->currentStage($project)['key']);
    }

    public function test_a_posted_issue_voucher_puts_the_operation_in_the_factory(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $order = WorkOrder::factory()->create(['project_id' => $project->id]);

        IssueVoucher::factory()->create([
            'work_order_id' => $order->id,
            'status' => VoucherStatus::Posted,
            'voucher_date' => '2026-05-10',
        ]);

        $stage = $this->stage($project, 'in_factory');

        $this->assertTrue($stage['reached']);
        $this->assertSame('2026-05-10', $stage['at']->toDateString());
    }

    public function test_a_draft_issue_voucher_does_not_move_the_operation_into_the_factory(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $order = WorkOrder::factory()->create(['project_id' => $project->id]);

        IssueVoucher::factory()->create([
            'work_order_id' => $order->id,
            'status' => VoucherStatus::Draft,
        ]);

        $this->assertFalse($this->stage($project, 'in_factory')['reached']);
    }

    public function test_manufacturing_counts_as_finished_only_when_every_work_order_is(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $first = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'manufacturing_finished_at' => '2026-06-01 10:00:00',
        ]);
        $second = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'manufacturing_finished_at' => null,
        ]);

        $this->assertFalse($this->stage($project, 'manufacturing_finished')['reached']);

        $second->forceFill(['manufacturing_finished_at' => '2026-07-15 09:00:00'])->save();

        $stage = $this->stage($project->fresh(), 'manufacturing_finished');

        $this->assertTrue($stage['reached']);
        // The date is when the LAST order landed, not the first.
        $this->assertSame('2026-07-15', $stage['at']->toDateString());
    }

    public function test_delivery_and_installation_advance_the_timeline(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        DeliveryVoucher::factory()->create([
            'project_id' => $project->id,
            'activated_at' => '2026-08-01 12:00:00',
        ]);

        $this->assertTrue($this->stage($project, 'delivered')['reached']);
        $this->assertFalse($this->stage($project, 'installed')['reached']);

        Installation::factory()->create([
            'project_id' => $project->id,
            'completed_at' => '2026-09-05 16:00:00',
        ]);

        $installed = $this->stage($project->fresh(), 'installed');

        $this->assertTrue($installed['reached']);
        $this->assertSame('2026-09-05', $installed['at']->toDateString());
        $this->assertSame('installed', app(OperationTimelineService::class)->currentStage($project->fresh())['key']);
    }

    public function test_a_pipeline_operation_has_not_been_activated_yet(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Tender]);

        $this->assertFalse($this->stage($project, 'activated')['reached']);
        // Only the sales intake stage is behind it.
        $this->assertSame('sales', app(OperationTimelineService::class)->currentStage($project)['key']);
    }

    public function test_every_stage_is_returned_whether_reached_or_not(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $timeline = app(OperationTimelineService::class)->for($project);

        $this->assertSame([
            'sales', 'activated', 'technical_office', 'order_approved', 'in_factory',
            'manufacturing_finished', 'qa_approved', 'delivered', 'installed', 'completed',
        ], $timeline->pluck('key')->all());
    }
}
