<?php

namespace Tests\Feature\Sales;

use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\SalesPipelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LostFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_tender_can_be_cancelled_to_lost_with_reason_and_winner(): void
    {
        $project = Project::factory()->tender()->create();

        app(SalesPipelineService::class)->cancelToLost(
            $project,
            LostReason::HighPrice,
            'They went with cheaper supplier',
            'ACME Co.',
        );

        $fresh = $project->fresh();
        $this->assertSame(ProjectStatus::Lost, $fresh->status);
        $this->assertSame(LostReason::HighPrice, $fresh->lost_reason);
        $this->assertSame('They went with cheaper supplier', $fresh->lost_reason_note);
        $this->assertSame('ACME Co.', $fresh->winning_competitor);
    }

    public function test_inhand_can_be_cancelled_to_lost(): void
    {
        $project = Project::factory()->inHand()->create();

        app(SalesPipelineService::class)->cancelToLost(
            $project,
            LostReason::NotApprovedByConsultant,
            null,
            null,
        );

        $this->assertSame(ProjectStatus::Lost, $project->fresh()->status);
    }

    public function test_lost_reason_is_persisted_as_enum(): void
    {
        $project = Project::factory()->tender()->create();
        app(SalesPipelineService::class)->cancelToLost($project, LostReason::PaymentFacilities);

        $this->assertInstanceOf(LostReason::class, $project->fresh()->lost_reason);
        $this->assertSame('payment_facilities', $project->fresh()->lost_reason->value);
    }
}
