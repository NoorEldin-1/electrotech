<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use App\Services\SalesPipelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_next_version_for_starts_at_one(): void
    {
        $project = Project::factory()->draft()->create();
        $this->assertSame(1, ProjectOffer::nextVersionFor($project->id));
    }

    public function test_next_version_increments_per_project(): void
    {
        $project = Project::factory()->draft()->create();
        ProjectOffer::factory()->for($project)->create(['version' => 1]);
        ProjectOffer::factory()->for($project)->create(['version' => 2]);

        $this->assertSame(3, ProjectOffer::nextVersionFor($project->id));
    }

    public function test_versions_are_isolated_per_project(): void
    {
        $a = Project::factory()->draft()->create();
        $b = Project::factory()->draft()->create();
        ProjectOffer::factory()->for($a)->create(['version' => 1]);
        ProjectOffer::factory()->for($a)->create(['version' => 2]);
        ProjectOffer::factory()->for($b)->create(['version' => 1]);

        $this->assertSame(3, ProjectOffer::nextVersionFor($a->id));
        $this->assertSame(2, ProjectOffer::nextVersionFor($b->id));
    }

    public function test_latest_offer_returns_highest_version(): void
    {
        $project = Project::factory()->draft()->create();
        ProjectOffer::factory()->for($project)->create(['version' => 1, 'financial_amount' => 1000]);
        ProjectOffer::factory()->for($project)->create(['version' => 2, 'financial_amount' => 2000]);
        ProjectOffer::factory()->for($project)->create(['version' => 3, 'financial_amount' => 3000]);

        $this->assertSame('3000.00', $project->fresh()->latestOffer->financial_amount);
    }

    public function test_record_offer_auto_increments_version(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->draft()->create();
        $service = app(SalesPipelineService::class);

        $first = $service->recordOffer($project, ['financial_amount' => 1000]);
        $second = $service->recordOffer($project->fresh(), ['financial_amount' => 1500]);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($user->id, $first->submitted_by);
    }
}
