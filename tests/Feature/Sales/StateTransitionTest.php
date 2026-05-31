<?php

namespace Tests\Feature\Sales;

use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use App\Services\SalesPipelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected SalesPipelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->service = app(SalesPipelineService::class);
    }

    public function test_happy_path_draft_to_active(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');
        $this->actingAs($user);

        $project = Project::factory()->draft()->create();
        ProjectOffer::factory()->for($project)->create(['version' => 1]);

        $this->service->moveToTender($project->fresh());
        $this->assertSame(ProjectStatus::Tender, $project->fresh()->status);

        $this->service->moveToInHand($project->fresh());
        $fresh = $project->fresh();
        $this->assertSame(ProjectStatus::InHand, $fresh->status);
        $this->assertSame('pending', $fresh->smb_status);

        $fresh->acceptance_email_at = now()->toDateString();
        $fresh->manager_approved_at = now();
        $fresh->manager_approved_by = $user->id;
        $fresh->save();

        $this->service->moveToActive($fresh->fresh());
        $final = $project->fresh();
        $this->assertSame(ProjectStatus::InProgress, $final->status);
        $this->assertTrue($final->latestOffer->is_winning);
    }

    public function test_move_to_tender_requires_at_least_one_offer(): void
    {
        $project = Project::factory()->draft()->create();

        $this->expectException(DomainException::class);
        $this->service->moveToTender($project);
    }

    public function test_move_to_active_rejects_without_acceptance_email(): void
    {
        $project = Project::factory()->inHand()->create([
            'acceptance_email_at' => null,
            'manager_approved_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->service->moveToActive($project);
    }

    public function test_move_to_active_rejects_without_manager_approval(): void
    {
        $project = Project::factory()->inHand()->create([
            'acceptance_email_at' => now()->toDateString(),
            'manager_approved_at' => null,
        ]);

        $this->expectException(DomainException::class);
        $this->service->moveToActive($project);
    }

    public function test_cannot_move_from_active_back_to_tender(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $this->expectException(DomainException::class);
        $this->service->moveToTender($project);
    }

    public function test_cannot_transition_out_of_lost(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Lost]);

        $this->expectException(DomainException::class);
        $this->service->moveToInHand($project);
    }
}
