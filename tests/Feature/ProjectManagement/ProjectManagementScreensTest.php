<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectManagement;

use App\Enums\AttachmentCategory;
use App\Enums\InstallationStatus;
use App\Enums\InteractionType;
use App\Enums\ProjectStatus;
use App\Enums\WorkOrderPhase;
use App\Filament\Pages\ProjectsTimeline;
use App\Filament\Resources\CustomerInteractionResource;
use App\Filament\Resources\CustomerInteractionResource\Pages\CreateCustomerInteraction;
use App\Filament\Resources\CustomerInteractionResource\Pages\ListCustomerInteractions;
use App\Filament\Resources\InstallationResource\Pages\ListInstallations;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\CustomerInteractionsRelationManager;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Installation;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OperationTimelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation.pptx — قسم إدارة المشروعات: the screens built for it (the
 * operations timeline page, customer follow-up, the timeline on the operation
 * file, the work-order phase and finishing an installation with the minutes).
 */
class ProjectManagementScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_timeline_page_lists_active_operations_with_their_stage(): void
    {
        $this->as('Admin');

        $active = Project::factory()->create(['status' => ProjectStatus::InProgress, 'end_date' => now()->addDays(4)]);
        $tender = Project::factory()->create(['status' => ProjectStatus::Tender]);
        Attachment::factory()->create(['project_id' => $active->id, 'category' => AttachmentCategory::PreliminaryDrawing->value]);

        Livewire::test(ProjectsTimeline::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$tender])
            ->assertSee(__('resources.operation_timeline.stages.preliminary_drawing'))
            ->assertSee(__('resources.operation_timeline.summary.days_remaining', ['days' => 4]));
    }

    public function test_the_timeline_page_filters_by_current_phase_and_opens_the_full_timeline(): void
    {
        $this->as('Admin');

        $early = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $late = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        Attachment::factory()->create(['project_id' => $late->id, 'category' => AttachmentCategory::ExecutiveDrawing->value]);

        Livewire::test(ProjectsTimeline::class)
            ->filterTable('current_group', 'executive')
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$early])
            ->mountTableAction('timeline', $late)
            ->assertSee(__('resources.operation_timeline.groups.handover'))
            ->assertSee(__('resources.operation_timeline.skipped'));
    }

    public function test_the_timeline_page_is_gated_by_the_timeline_permission(): void
    {
        $this->as('Procurement');
        $this->assertFalse(ProjectsTimeline::canAccess());

        foreach (['Sales', 'Sales_Manager', 'Technical_Office', 'Factory_Manager', 'General_Manager'] as $role) {
            $this->as($role);
            $this->assertTrue(ProjectsTimeline::canAccess(), $role);
        }
    }

    public function test_sales_logs_a_customer_contact_and_the_timeline_moves(): void
    {
        $this->as('Sales');
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress, 'customer_id' => $customer->id]);

        Livewire::test(CreateCustomerInteraction::class)
            ->fillForm([
                'project_id' => $project->id,
                'type' => InteractionType::Contact->value,
                'channel' => 'call',
                'interacted_at' => now()->toDateTimeString(),
                'notes' => 'اتصال أول',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $interaction = CustomerInteraction::firstOrFail();
        // The customer is taken from the operation.
        $this->assertSame($customer->id, $interaction->customer_id);

        Livewire::test(ListCustomerInteractions::class)->assertCanSeeTableRecords([$interaction]);

        $this->assertTrue(
            app(OperationTimelineService::class)->for($project)->firstWhere('key', 'customer_contact')['reached']
        );
    }

    public function test_site_ready_is_only_kept_on_a_site_readiness_entry(): void
    {
        $this->as('Sales');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $interaction = CustomerInteraction::factory()->create([
            'project_id' => $project->id,
            'type' => InteractionType::Contact,
            'site_ready' => true,
        ]);

        $this->assertFalse($interaction->fresh()->site_ready);
    }

    public function test_customer_follow_up_is_view_only_for_the_pmo(): void
    {
        $this->as('Technical_Office');
        $this->assertTrue(CustomerInteractionResource::canViewAny());
        $this->assertFalse(CustomerInteractionResource::canCreate());

        $this->as('Procurement');
        $this->assertFalse(CustomerInteractionResource::canViewAny());
    }

    public function test_the_operation_file_shows_the_timeline_and_the_follow_up_tab(): void
    {
        $this->as('Admin');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        CustomerInteraction::factory()->create(['project_id' => $project->id]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertOk()
            ->assertSee(__('resources.operation_timeline.heading'))
            ->assertSee(__('resources.projects.attachment_groups.drawings'))
            ->assertSee(__('resources.enums.attachment_category.handover_minutes'));

        Livewire::test(CustomerInteractionsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ])
            ->assertOk()
            ->callTableAction('create', data: [
                'type' => InteractionType::SiteReadiness->value,
                'interacted_at' => now()->toDateTimeString(),
                'site_ready' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('customer_interactions', [
            'project_id' => $project->id,
            'type' => InteractionType::SiteReadiness->value,
            'site_ready' => true,
        ]);
    }

    public function test_the_work_order_form_saves_the_timeline_phase(): void
    {
        $this->as('Admin');
        $order = WorkOrder::factory()->create(['bom_id' => null, 'planned_quantity' => 3]);
        $order->outputs()->create(['item_id' => Item::factory()->create()->id, 'planned_quantity' => 3]);
        $this->assertSame(WorkOrderPhase::Preliminary, $order->timeline_phase);

        Livewire::test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->assertOk()
            ->fillForm(['timeline_phase' => WorkOrderPhase::Executive->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(WorkOrderPhase::Executive, $order->fresh()->timeline_phase);
    }

    public function test_finishing_an_installation_from_the_list_requires_the_minutes(): void
    {
        Storage::fake('public');
        $this->as('Factory_Manager');

        $installation = Installation::factory()->create(['status' => InstallationStatus::InProgress, 'started_at' => now()]);

        Livewire::test(ListInstallations::class)
            ->callTableAction('complete', $installation, data: ['handover_minutes' => null])
            ->assertHasTableActionErrors(['handover_minutes' => 'required']);

        $this->assertSame(InstallationStatus::InProgress, $installation->fresh()->status);

        Livewire::test(ListInstallations::class)
            ->callTableAction('complete', $installation, data: [
                'handover_minutes' => UploadedFile::fake()->create('minutes.pdf', 20, 'application/pdf'),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(InstallationStatus::Completed, $installation->fresh()->status);
        $this->assertDatabaseHas('attachments', [
            'project_id' => $installation->project_id,
            'category' => AttachmentCategory::HandoverMinutes->value,
        ]);
    }
}
