<?php

namespace Tests\Feature\Sales;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages\AttachmentPersistence;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use App\Services\SalesAlertService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        // The offer-attached alert (Slide 11) may already have fired; the
        // missing-offer command itself must add nothing on top of it.
        $before = $sales->fresh()->notifications()->count();

        $this->artisan('sales:notify-incomplete-operations')->assertSuccessful();

        $this->assertSame($before, $sales->fresh()->notifications()->count());
    }

    public function test_attaching_an_offer_to_a_pipeline_operation_notifies_sales(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $sales = User::factory()->create();
        $sales->assignRole('Sales');

        $project = Project::factory()->tender()->create();
        ProjectOffer::factory()->for($project)->create(['financial_amount' => 1000]);

        $this->assertSame(1, $sales->fresh()->notifications()->count());
    }

    public function test_attaching_an_offer_outside_the_pipeline_does_not_notify(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $sales = User::factory()->create();
        $sales->assignRole('Sales');

        $project = Project::factory()->active()->create();
        ProjectOffer::factory()->for($project)->create(['financial_amount' => 1000]);

        $this->assertSame(0, $sales->fresh()->notifications()->count());
    }

    public function test_uploading_a_submittal_notifies_sales(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('public');
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);

        $project = Project::factory()->create();
        $path = "attachments/{$project->id}/submittal/doc.pdf";
        Storage::disk('public')->put($path, 'PDF');

        AttachmentPersistence::sync($project, [AttachmentCategory::Submittal->value => [$path]]);

        $this->assertDatabaseHas('attachments', ['project_id' => $project->id, 'category' => 'submittal']);
        $this->assertSame(1, $sales->fresh()->notifications()->count());
    }
}
