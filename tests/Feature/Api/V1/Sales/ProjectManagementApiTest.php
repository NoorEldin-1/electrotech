<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Enums\AttachmentCategory;
use App\Enums\InstallationStatus;
use App\Enums\ProjectStatus;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Presentation.pptx — قسم إدارة المشروعات over the API: customer follow-up,
 * the reworked operation timeline, the operations-timeline list, the
 * work-order phase and the new attachment categories.
 */
class ProjectManagementApiTest extends ApiTestCase
{
    public function test_customer_interactions_crud_takes_the_customer_from_the_operation(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress, 'customer_id' => $customer->id]);
        $user = $this->userWith(['customer_interactions.view', 'customer_interactions.manage']);

        $created = $this->actingAsApi($user)->apiPost(self::BASE.'/customer-interactions', [
            'project_id' => $project->id,
            'type' => 'site_readiness',
            'channel' => 'visit',
            'site_ready' => true,
            'notes' => 'Site handed over by the contractor',
        ]);

        $created->assertCreated();
        $this->assertItemEnvelope($created);
        $created->assertJsonPath('data.customer_id', $customer->id);
        $created->assertJsonPath('data.interaction_type.value', 'site_readiness');
        $created->assertJsonPath('data.site_ready', true);

        $id = $created->json('data.id');

        $list = $this->actingAsApi($user)->apiGet(self::BASE.'/customer-interactions?filter[project]='.$project->id);
        $list->assertOk();
        $this->assertPaginatedEnvelope($list);
        $list->assertJsonPath('data.0.id', $id);

        $this->actingAsApi($user)
            ->apiPatch(self::BASE."/customer-interactions/{$id}", ['notes' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Updated');

        $this->actingAsApi($user)->apiDelete(self::BASE."/customer-interactions/{$id}")->assertNoContent();
        $this->assertSoftDeleted('customer_interactions', ['id' => $id]);
    }

    public function test_customer_interactions_need_their_permissions(): void
    {
        $project = Project::factory()->create();
        $viewer = $this->userWith(['customer_interactions.view']);

        $this->actingAsApi($viewer)->apiGet(self::BASE.'/customer-interactions')->assertOk();
        $this->actingAsApi($viewer)
            ->apiPost(self::BASE.'/customer-interactions', ['project_id' => $project->id, 'type' => 'contact'])
            ->assertForbidden();

        $this->actingAsApi($this->userWithoutPermissions())
            ->apiGet(self::BASE.'/customer-interactions')
            ->assertForbidden();
    }

    public function test_the_operation_timeline_returns_groups_skips_and_a_summary(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress, 'end_date' => now()->addDays(9)]);
        Attachment::factory()->create(['project_id' => $project->id, 'category' => AttachmentCategory::ExecutiveDrawing->value]);

        $response = $this->actingAsApi($this->userWith(['operations.timeline']))
            ->apiGet(self::BASE."/projects/{$project->id}/timeline");

        $response->assertOk();
        $response->assertJsonPath('data.current_stage.key', 'executive_drawing');
        $response->assertJsonPath('data.current_stage.group', 'executive');
        $response->assertJsonPath('data.summary.days_remaining', 9);
        $response->assertJsonCount(18, 'data.stages');

        $stages = collect($response->json('data.stages'))->keyBy('key');
        $this->assertTrue($stages['preliminary_mo_created']['skipped']);
        $this->assertSame('preliminary', $stages['preliminary_mo_created']['group']);
    }

    public function test_the_operation_timeline_still_opens_for_the_overview_permission(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['operations.overview']))
            ->apiGet(self::BASE."/projects/{$project->id}/timeline")
            ->assertOk();

        $this->actingAsApi($this->userWithoutPermissions())
            ->apiGet(self::BASE."/projects/{$project->id}/timeline")
            ->assertForbidden();
    }

    public function test_the_operations_timeline_list_filters_by_group_and_overdue(): void
    {
        $early = Project::factory()->create(['status' => ProjectStatus::InProgress, 'end_date' => now()->subDays(3)]);
        $late = Project::factory()->create(['status' => ProjectStatus::InProgress, 'end_date' => now()->addDays(3)]);
        Project::factory()->create(['status' => ProjectStatus::Tender]);
        Attachment::factory()->create(['project_id' => $late->id, 'category' => AttachmentCategory::ExecutiveApproval->value]);

        $user = $this->userWith(['operations.timeline']);

        $all = $this->actingAsApi($user)->apiGet(self::BASE.'/operations-timeline');
        $all->assertOk();
        $this->assertEqualsCanonicalizing([$early->id, $late->id], collect($all->json('data'))->pluck('project_id')->all());

        $executive = $this->actingAsApi($user)->apiGet(self::BASE.'/operations-timeline?group=executive');
        $this->assertSame([$late->id], collect($executive->json('data'))->pluck('project_id')->all());

        $overdue = $this->actingAsApi($user)->apiGet(self::BASE.'/operations-timeline?overdue=1');
        $this->assertSame([$early->id], collect($overdue->json('data'))->pluck('project_id')->all());
        $overdue->assertJsonPath('data.0.days_overdue', 3);

        $this->actingAsApi($this->userWith(['operations.overview']))
            ->apiGet(self::BASE.'/operations-timeline')
            ->assertForbidden();
    }

    public function test_projects_carry_their_current_stage(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        Attachment::factory()->create(['project_id' => $project->id, 'category' => AttachmentCategory::PreliminaryApproval->value]);
        $user = $this->userWith(['projects.view']);

        $this->actingAsApi($user)->apiGet(self::BASE."/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.current_stage.key', 'preliminary_approval');

        $this->actingAsApi($user)->apiGet(self::BASE.'/projects')
            ->assertOk()
            ->assertJsonPath('data.0.current_stage.key', 'preliminary_approval');
    }

    public function test_work_orders_accept_and_return_the_timeline_phase(): void
    {
        $project = Project::factory()->create();
        $user = $this->userWith(['work_orders.view', 'work_orders.create', 'work_orders.edit']);

        $created = $this->actingAsApi($user)->apiPost(self::BASE.'/work-orders', [
            'project_id' => $project->id,
            'title' => 'Shop-drawing order',
            'timeline_phase' => 'executive',
        ]);
        $created->assertCreated();
        $created->assertJsonPath('data.timeline_phase.value', 'executive');

        // Omitted: derived — no shop-drawing approval yet, so preliminary.
        $derived = $this->actingAsApi($user)->apiPost(self::BASE.'/work-orders', [
            'project_id' => $project->id,
            'title' => 'First order',
        ]);
        $derived->assertJsonPath('data.timeline_phase.value', 'preliminary');

        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/work-orders/'.$derived->json('data.id'), ['timeline_phase' => 'executive'])
            ->assertOk()
            ->assertJsonPath('data.timeline_phase.value', 'executive');

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/work-orders', ['project_id' => $project->id, 'title' => 'x', 'timeline_phase' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_uploading_handover_minutes_through_attachments_finishes_the_installation(): void
    {
        Storage::fake('public');
        $installation = Installation::factory()->create(['status' => InstallationStatus::InProgress, 'started_at' => now()]);

        // The observer is under test here, not the upload permission.
        $this->actingAsApi($this->admin())
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'project',
                'owner_id' => $installation->project_id,
                'category' => 'handover_minutes',
                'file' => UploadedFile::fake()->create('minutes.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated();

        $this->assertSame(InstallationStatus::Completed, $installation->fresh()->status);
    }

    public function test_the_enum_catalog_exposes_the_new_enums(): void
    {
        $response = $this->actingAsApi($this->userWithoutPermissions())
            ->apiGet(self::BASE.'/meta/enums?only=interaction_type,interaction_channel,work_order_phase,attachment_category');

        $response->assertOk();
        $this->assertSame(['contact', 'site_readiness', 'follow_up'], collect($response->json('data.interaction_type'))->pluck('value')->all());
        $this->assertContains('handover_minutes', collect($response->json('data.attachment_category'))->pluck('value')->all());
        $this->assertSame(['preliminary', 'executive'], collect($response->json('data.work_order_phase'))->pluck('value')->all());
    }
}
