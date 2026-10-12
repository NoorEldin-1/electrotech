<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\AttachmentCategory;
use App\Enums\InstallationStatus;
use App\Enums\InteractionType;
use App\Enums\ProjectStatus;
use App\Enums\WorkOrderPhase;
use App\Enums\WorkOrderStatus;
use App\Models\Attachment;
use App\Models\CustomerInteraction;
use App\Models\DeliveryVoucher;
use App\Models\Installation;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\WorkOrder;
use App\Services\OperationTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Presentation.pptx — قسم إدارة المشروعات: the operation timeline (سلايد 4) and
 * the rule for when each stage counts as done (سلايد 5–8).
 *
 * The timeline is derived, never stored, so these tests create the underlying
 * records and check the stage follows on its own.
 */
class OperationTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OperationTimelineService
    {
        return app(OperationTimelineService::class);
    }

    private function stage(Project $project, string $key): array
    {
        return $this->service()->for($project->fresh())->firstWhere('key', $key);
    }

    private function active(array $attributes = []): Project
    {
        return Project::factory()->create($attributes + ['status' => ProjectStatus::InProgress]);
    }

    private function attach(Project $project, AttachmentCategory $category, string $at = '2026-05-01 10:00:00'): Attachment
    {
        return Attachment::factory()->create([
            'project_id' => $project->id,
            'category' => $category->value,
            'created_at' => $at,
        ]);
    }

    public function test_every_stage_is_returned_in_the_deck_order_and_grouped(): void
    {
        $timeline = $this->service()->for($this->active());

        $this->assertSame([
            'sales', 'customer_contact', 'customer_drawings', 'preliminary_drawing', 'preliminary_approval',
            'preliminary_mo_created', 'preliminary_mo_started', 'preliminary_mo_finished',
            'site_readiness', 'site_measurement', 'executive_drawing', 'executive_approval',
            'executive_mo_created', 'executive_mo_started', 'executive_mo_finished',
            'delivered', 'installation_started', 'installation_finished',
        ], $timeline->pluck('key')->all());

        $this->assertSame(['preliminary', 'executive', 'handover'], $timeline->pluck('group')->unique()->values()->all());
    }

    public function test_a_new_operation_has_only_reached_sales(): void
    {
        $project = $this->active();

        $current = $this->service()->currentStage($project);

        $this->assertSame('sales', $current['key']);
        $this->assertSame('preliminary', $current['group']);
        $this->assertFalse($this->stage($project, 'customer_contact')['reached']);
    }

    public function test_customer_contact_comes_from_the_customer_follow_up_log(): void
    {
        $project = $this->active();

        CustomerInteraction::factory()->create(['project_id' => $project->id, 'type' => InteractionType::FollowUp]);
        $this->assertFalse($this->stage($project, 'customer_contact')['reached']);

        CustomerInteraction::factory()->create([
            'project_id' => $project->id,
            'type' => InteractionType::Contact,
            'interacted_at' => '2026-04-02 09:00:00',
        ]);

        $stage = $this->stage($project, 'customer_contact');
        $this->assertTrue($stage['reached']);
        $this->assertSame('2026-04-02', $stage['at']->toDateString());
    }

    public function test_drawing_stages_are_reached_when_their_file_is_uploaded_and_revert_when_it_is_deleted(): void
    {
        $project = $this->active();

        $map = [
            'customer_drawings' => AttachmentCategory::CustomerDrawings,
            'preliminary_drawing' => AttachmentCategory::PreliminaryDrawing,
            'preliminary_approval' => AttachmentCategory::PreliminaryApproval,
            'executive_drawing' => AttachmentCategory::ExecutiveDrawing,
            'executive_approval' => AttachmentCategory::ExecutiveApproval,
        ];

        foreach ($map as $key => $category) {
            $this->assertFalse($this->stage($project, $key)['reached'], $key);
            $file = $this->attach($project, $category, '2026-05-03 08:00:00');
            $this->assertSame('2026-05-03', $this->stage($project, $key)['at']?->toDateString(), $key);

            $file->delete();
            $this->assertFalse($this->stage($project, $key)['reached'], "{$key} after delete");
        }
    }

    public function test_site_readiness_needs_a_site_readiness_entry_marked_ready(): void
    {
        $project = $this->active();

        CustomerInteraction::factory()->create([
            'project_id' => $project->id,
            'type' => InteractionType::SiteReadiness,
            'site_ready' => false,
        ]);
        $this->assertFalse($this->stage($project, 'site_readiness')['reached']);

        CustomerInteraction::factory()->siteReady()->create([
            'project_id' => $project->id,
            'interacted_at' => '2026-06-10 11:00:00',
        ]);
        $this->assertSame('2026-06-10', $this->stage($project, 'site_readiness')['at']->toDateString());
    }

    public function test_site_measurement_is_the_measurement_file_or_a_site_survey(): void
    {
        $project = $this->active();
        SiteSurvey::factory()->create(['project_id' => $project->id, 'survey_date' => '2026-06-12']);

        $this->assertSame('2026-06-12', $this->stage($project, 'site_measurement')['at']->toDateString());

        $other = $this->active();
        $this->attach($other, AttachmentCategory::SiteMeasurement, '2026-06-20 10:00:00');
        $this->assertSame('2026-06-20', $this->stage($other, 'site_measurement')['at']->toDateString());
    }

    public function test_a_work_order_defaults_to_the_executive_phase_once_the_shop_drawing_is_approved(): void
    {
        $project = $this->active();

        $first = WorkOrder::factory()->create(['project_id' => $project->id]);
        $this->assertSame(WorkOrderPhase::Preliminary, $first->timeline_phase);

        $this->attach($project, AttachmentCategory::ExecutiveApproval);

        $second = WorkOrder::factory()->create(['project_id' => $project->id]);
        $this->assertSame(WorkOrderPhase::Executive, $second->timeline_phase);

        // An explicit phase is respected, and clearing it re-derives instead of writing NULL.
        $explicit = WorkOrder::factory()->create(['project_id' => $project->id, 'timeline_phase' => WorkOrderPhase::Preliminary]);
        $this->assertSame(WorkOrderPhase::Preliminary, $explicit->timeline_phase);
        $explicit->update(['timeline_phase' => null]);
        $this->assertSame(WorkOrderPhase::Executive, $explicit->fresh()->timeline_phase);
    }

    public function test_manufacturing_stages_follow_the_orders_of_their_phase(): void
    {
        $project = $this->active();

        WorkOrder::factory()->create([
            'project_id' => $project->id,
            'timeline_phase' => WorkOrderPhase::Executive,
            'created_at' => '2026-07-01 08:00:00',
            'actual_start_date' => '2026-07-03 08:00:00',
            'manufacturing_finished_at' => '2026-07-10 08:00:00',
        ]);
        $b = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'timeline_phase' => WorkOrderPhase::Executive,
            'created_at' => '2026-07-02 08:00:00',
        ]);

        $this->assertSame('2026-07-01', $this->stage($project, 'executive_mo_created')['at']->toDateString());
        $this->assertSame('2026-07-03', $this->stage($project, 'executive_mo_started')['at']->toDateString());
        // Not finished while one order of the phase is still on the line.
        $this->assertFalse($this->stage($project, 'executive_mo_finished')['reached']);
        // The other phase is untouched.
        $this->assertFalse($this->stage($project, 'preliminary_mo_created')['reached']);

        $b->forceFill(['manufacturing_finished_at' => '2026-07-15 09:00:00'])->save();
        // Finished on the date the LAST order landed.
        $this->assertSame('2026-07-15', $this->stage($project, 'executive_mo_finished')['at']->toDateString());
    }

    public function test_a_cancelled_order_neither_starts_nor_blocks_its_phase(): void
    {
        $project = $this->active();

        WorkOrder::factory()->create([
            'project_id' => $project->id,
            'timeline_phase' => WorkOrderPhase::Preliminary,
            'manufacturing_finished_at' => '2026-05-20 10:00:00',
        ]);
        WorkOrder::factory()->create([
            'project_id' => $project->id,
            'timeline_phase' => WorkOrderPhase::Preliminary,
            'status' => WorkOrderStatus::Cancelled,
        ]);

        $this->assertTrue($this->stage($project, 'preliminary_mo_finished')['reached']);
    }

    public function test_the_preliminary_order_is_skipped_when_the_operation_went_straight_to_the_executive_phase(): void
    {
        $project = $this->active();
        $this->assertFalse($this->stage($project, 'preliminary_mo_created')['skipped']);

        $this->attach($project, AttachmentCategory::ExecutiveDrawing);

        foreach (['preliminary_mo_created', 'preliminary_mo_started', 'preliminary_mo_finished'] as $key) {
            $stage = $this->stage($project, $key);
            $this->assertTrue($stage['skipped'], $key);
            $this->assertFalse($stage['reached'], $key);
        }
    }

    public function test_handover_minutes_finish_the_running_installation(): void
    {
        $project = $this->active();
        DeliveryVoucher::factory()->create(['project_id' => $project->id, 'activated_at' => '2026-08-01 12:00:00']);

        $installation = Installation::factory()->create([
            'project_id' => $project->id,
            'status' => InstallationStatus::InProgress,
            'started_at' => '2026-08-03 09:00:00',
        ]);

        $this->assertTrue($this->stage($project, 'delivered')['reached']);
        $this->assertSame('2026-08-03', $this->stage($project, 'installation_started')['at']->toDateString());
        $this->assertFalse($this->stage($project, 'installation_finished')['reached']);

        $this->attach($project, AttachmentCategory::HandoverMinutes, now()->toDateTimeString());

        $this->assertSame(InstallationStatus::Completed, $installation->fresh()->status);
        $this->assertTrue($this->stage($project, 'installation_finished')['reached']);

        $current = $this->service()->currentStage($project->fresh());
        $this->assertSame('installation_finished', $current['key']);
        $this->assertSame('handover', $current['group']);
    }

    public function test_the_summary_counts_down_to_the_expected_delivery(): void
    {
        $this->travelTo('2026-10-01 10:00:00');

        $ahead = $this->active(['end_date' => '2026-10-11']);
        $summary = $this->service()->summary($ahead);
        $this->assertSame(10, $summary['days_remaining']);
        $this->assertNull($summary['days_overdue']);
        $this->assertSame('2026-10-11', $summary['expected_delivery']->toDateString());

        $late = $this->active(['end_date' => '2026-09-26']);
        $summary = $this->service()->summary($late);
        $this->assertNull($summary['days_remaining']);
        $this->assertSame(5, $summary['days_overdue']);

        // Once delivered, nothing is "remaining" or "overdue" any more.
        DeliveryVoucher::factory()->create(['project_id' => $late->id, 'activated_at' => '2026-09-30 10:00:00']);
        $summary = $this->service()->summary($late->fresh());
        $this->assertNull($summary['days_overdue']);
        $this->assertSame('2026-09-30', $summary['delivered_at']->toDateString());

        $unset = $this->active(['end_date' => null]);
        $summary = $this->service()->summary($unset);
        $this->assertNull($summary['expected_delivery']);
        $this->assertNull($summary['days_remaining']);
    }

    public function test_progress_counts_reached_and_skipped_stages(): void
    {
        $project = $this->active();

        // sales only: 1 of 18
        $this->assertSame((int) round(100 / 18), $this->service()->summary($project)['progress_percent']);

        // executive drawing reached + the 3 skipped preliminary-order stages: 5 of 18
        $this->attach($project, AttachmentCategory::ExecutiveDrawing);
        $this->assertSame((int) round(500 / 18), $this->service()->summary($project->fresh())['progress_percent']);
    }

    public function test_for_many_matches_for_and_runs_a_fixed_number_of_queries(): void
    {
        $projects = Project::factory()->count(6)->create(['status' => ProjectStatus::InProgress]);
        foreach ($projects as $project) {
            WorkOrder::factory()->create(['project_id' => $project->id]);
            $this->attach($project, AttachmentCategory::PreliminaryDrawing);
        }

        $projects = Project::query()->with('customer')->whereIn('id', $projects->pluck('id'))->get();

        DB::enableQueryLog();
        $many = $this->service()->forMany($projects);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queries, 'forMany must not query per operation.');
        $this->assertCount(6, $many);

        $first = $projects->first();
        $this->assertEquals(
            $this->service()->for($first)->pluck('reached', 'key')->all(),
            $many[$first->id]->pluck('reached', 'key')->all(),
        );
    }
}
