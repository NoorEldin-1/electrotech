<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Models\CreditFacility;
use App\Models\FacilityAllocation;
use App\Models\Project;
use App\Services\CreditFacilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditFacilityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CreditFacilityService
    {
        return app(CreditFacilityService::class);
    }

    public function test_available_equals_limit_minus_active_allocations(): void
    {
        $facility = CreditFacility::factory()->create(['limit_amount' => 100000]);
        $project = Project::factory()->active()->create();

        $this->service()->allocate($facility, $project, 30000);
        $this->service()->allocate($facility, $project, 20000);

        $this->assertEquals(50000.0, $facility->fresh()->used_amount);
        $this->assertEquals(50000.0, $facility->fresh()->available_amount);
    }

    public function test_allocation_exceeding_available_is_rejected(): void
    {
        $facility = CreditFacility::factory()->create(['limit_amount' => 10000]);
        $project = Project::factory()->active()->create();

        $this->expectException(\DomainException::class);
        $this->service()->allocate($facility, $project, 15000);
    }

    public function test_release_returns_headroom(): void
    {
        $facility = CreditFacility::factory()->create(['limit_amount' => 100000]);
        $project = Project::factory()->active()->create();

        $allocation = $this->service()->allocate($facility, $project, 40000);
        $this->assertEquals(60000.0, $facility->fresh()->available_amount);

        $this->service()->release($allocation);
        $this->assertEquals(100000.0, $facility->fresh()->available_amount);

        // Releasing again is a no-op.
        $this->service()->release($allocation->fresh());
        $this->assertEquals(100000.0, $facility->fresh()->available_amount);
    }

    public function test_utilization_report(): void
    {
        $facility = CreditFacility::factory()->create(['limit_amount' => 200000]);
        FacilityAllocation::factory()->create(['credit_facility_id' => $facility->id, 'allocated_amount' => 50000, 'status' => 'active']);
        FacilityAllocation::factory()->released()->create(['credit_facility_id' => $facility->id, 'allocated_amount' => 99999]);

        $u = $this->service()->utilization($facility->fresh());

        $this->assertEquals(200000.0, $u['limit']);
        $this->assertEquals(50000.0, $u['used']);   // released excluded
        $this->assertEquals(150000.0, $u['available']);
        $this->assertEqualsWithDelta(25.0, $u['percent'], 0.001);
    }
}
