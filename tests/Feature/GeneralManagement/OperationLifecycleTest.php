<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\ProjectStatus;
use App\Events\OperationActivated;
use App\Models\Project;
use App\Models\User;
use App\Services\OperationLifecycleService;
use App\Services\SalesPipelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OperationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function readyInHandProject(): Project
    {
        return Project::factory()->inHand()->create([
            'acceptance_email_at' => now(),
            'manager_approved_at' => now(),
        ]);
    }

    public function test_activation_dispatches_operation_activated_event(): void
    {
        Event::fake([OperationActivated::class]);
        $project = $this->readyInHandProject();

        app(SalesPipelineService::class)->moveToActive($project);

        Event::assertDispatched(OperationActivated::class, fn (OperationActivated $e) => $e->project->id === $project->id);
        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
    }

    public function test_activation_notifies_department_users(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $tech = User::factory()->create();
        $tech->assignRole('Technical_Office');
        $finance = User::factory()->create();
        $finance->assignRole('Finance');
        // A user with no department role must NOT be notified.
        $nobody = User::factory()->create();

        $project = $this->readyInHandProject();
        app(SalesPipelineService::class)->moveToActive($project);

        $this->assertSame(1, $tech->fresh()->notifications()->count());
        $this->assertSame(1, $finance->fresh()->notifications()->count());
        $this->assertSame(0, $nobody->fresh()->notifications()->count());
    }

    public function test_lifecycle_transitions_follow_the_rules(): void
    {
        $service = app(OperationLifecycleService::class);
        $project = Project::factory()->active()->create();

        $service->putOnHold($project);
        $this->assertSame(ProjectStatus::OnHold, $project->fresh()->status);

        $service->resume($project);
        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);

        $service->markCompleted($project);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_draft_cannot_be_completed(): void
    {
        $draft = Project::factory()->draft()->create();

        $this->expectException(\DomainException::class);
        app(OperationLifecycleService::class)->markCompleted($draft);
    }

    public function test_resume_requires_on_hold(): void
    {
        $active = Project::factory()->active()->create();

        $this->expectException(\DomainException::class);
        app(OperationLifecycleService::class)->resume($active);
    }
}
