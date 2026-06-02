<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\ClaimStatus;
use App\Enums\DeliveryVoucherStatus;
use App\Models\DeliveryVoucher;
use App\Models\FinancialClaim;
use App\Models\Project;
use App\Models\User;
use App\Services\FinancialClaimService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialClaimTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FinancialClaimService
    {
        return app(FinancialClaimService::class);
    }

    public function test_claim_auto_numbers(): void
    {
        $claim = FinancialClaim::factory()->create();

        $this->assertStringStartsWith('FC-', $claim->claim_number);
    }

    public function test_cannot_submit_before_supply_and_installation_complete(): void
    {
        $project = Project::factory()->active()->create(); // InProgress, nothing delivered
        $claim = FinancialClaim::factory()->create(['project_id' => $project->id]);

        $this->expectException(\DomainException::class);
        $this->service()->submit($claim);
    }

    public function test_submit_succeeds_when_operation_completed(): void
    {
        $project = Project::factory()->completed()->create();
        $claim = FinancialClaim::factory()->create(['project_id' => $project->id]);

        $this->service()->submit($claim);

        $claim->refresh();
        $this->assertSame(ClaimStatus::Submitted, $claim->status);
        $this->assertNotNull($claim->submitted_at);
    }

    public function test_submit_succeeds_when_delivery_is_active(): void
    {
        $project = Project::factory()->active()->create();
        DeliveryVoucher::factory()->create([
            'project_id' => $project->id,
            'status' => DeliveryVoucherStatus::Active,
        ]);
        $claim = FinancialClaim::factory()->create(['project_id' => $project->id]);

        $this->service()->submit($claim);

        $this->assertSame(ClaimStatus::Submitted, $claim->fresh()->status);
    }

    public function test_collect_requires_submitted(): void
    {
        $claim = FinancialClaim::factory()->submitted()->create();

        $this->service()->collect($claim);

        $claim->refresh();
        $this->assertSame(ClaimStatus::Collected, $claim->status);
        $this->assertNotNull($claim->collected_at);
    }

    public function test_collect_on_draft_throws(): void
    {
        $claim = FinancialClaim::factory()->create(); // draft

        $this->expectException(\DomainException::class);
        $this->service()->collect($claim);
    }

    public function test_workflow_actions_are_gated_by_policy(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $draft = FinancialClaim::factory()->create();
        $submitted = FinancialClaim::factory()->submitted()->create();

        $manager = User::factory()->create();
        $manager->assignRole('General_Manager');
        $this->assertTrue($manager->can('submit', $draft));
        $this->assertTrue($manager->can('collect', $submitted));

        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->assertFalse($sales->can('submit', $draft));
        $this->assertFalse($sales->can('collect', $submitted));
    }
}
