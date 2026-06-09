<?php

namespace Tests\Feature\Sales;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use App\Services\SalesAlertService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 5: the system flags pipeline operations that have no priced offer,
 * and a scheduled command notifies Sales.
 */
class SalesAlertTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SalesAlertService
    {
        return app(SalesAlertService::class);
    }

    public function test_tender_and_inhand_without_priced_offer_are_flagged(): void
    {
        $tender = Project::factory()->tender()->create();
        $inHand = Project::factory()->inHand()->create();

        $missing = $this->service()->operationsMissingOffers();

        $this->assertTrue($missing->contains($tender));
        $this->assertTrue($missing->contains($inHand));
        $this->assertFalse($tender->hasPricedOffer());
    }

    public function test_operation_with_a_priced_offer_is_not_flagged(): void
    {
        $project = Project::factory()->tender()->create();
        ProjectOffer::factory()->for($project)->create(['financial_amount' => 5000]);

        $this->assertFalse($this->service()->operationsMissingOffers()->contains($project));
        $this->assertTrue($project->hasPricedOffer());
    }

    public function test_a_zero_priced_offer_still_counts_as_missing(): void
    {
        $project = Project::factory()->tender()->create();
        ProjectOffer::factory()->for($project)->create(['financial_amount' => 0]);

        $this->assertTrue($this->service()->operationsMissingOffers()->contains($project));
    }

    public function test_operations_outside_the_pipeline_are_ignored(): void
    {
        $active = Project::factory()->active()->create();
        $lost = Project::factory()->create(['status' => ProjectStatus::Lost]);
        $draft = Project::factory()->draft()->create();

        $missing = $this->service()->operationsMissingOffers();

        $this->assertFalse($missing->contains($active));
        $this->assertFalse($missing->contains($lost));
        $this->assertFalse($missing->contains($draft));
    }

    public function test_command_sends_a_database_notification_to_sales(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        Project::factory()->tender()->create(); // missing offer

        $this->artisan('sales:notify-incomplete-operations')->assertSuccessful();

        $this->assertSame(1, $sales->fresh()->notifications()->count());
    }

    public function test_command_is_quiet_when_everything_is_priced(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $project = Project::factory()->tender()->create();
        ProjectOffer::factory()->for($project)->create(['financial_amount' => 1000]);

        $this->artisan('sales:notify-incomplete-operations')->assertSuccessful();

        $this->assertSame(0, $sales->fresh()->notifications()->count());
    }
}
