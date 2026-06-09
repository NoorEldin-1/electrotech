<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use App\Models\User;
use App\Services\SalesPipelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 1: the End Date is filled automatically the first time an operation
 * moves into Active Operations — but a date entered by hand is preserved.
 */
class EndDateOnActivationTest extends TestCase
{
    use RefreshDatabase;

    protected SalesPipelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->service = app(SalesPipelineService::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_end_date_is_set_to_today_when_empty(): void
    {
        $project = Project::factory()->inHand()->create([
            'acceptance_email_at' => now()->toDateString(),
            'manager_approved_at' => now(),
            'end_date' => null,
        ]);

        $this->service->moveToActive($project->fresh());

        $this->assertSame(now()->toDateString(), $project->fresh()->end_date?->toDateString());
    }

    public function test_existing_end_date_is_preserved(): void
    {
        $custom = now()->addDays(45)->toDateString();

        $project = Project::factory()->inHand()->create([
            'acceptance_email_at' => now()->toDateString(),
            'manager_approved_at' => now(),
            'end_date' => $custom,
        ]);

        $this->service->moveToActive($project->fresh());

        $this->assertSame($custom, $project->fresh()->end_date?->toDateString());
    }
}
