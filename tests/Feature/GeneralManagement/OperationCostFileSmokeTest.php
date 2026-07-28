<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\OperationCostFile;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\IssueVoucher;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CostCenterClosingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The cost-centre page renders with its closing section, and the closing /
 * reversal actions work through the UI (سلايد 12).
 */
class OperationCostFileSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    private function deliveredOperation(float $issued = 1000): Project
    {
        $project = Project::factory()->active()->create();

        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'status' => WorkOrderStatus::Completed]);
        IssueVoucher::factory()->posted()->create(['work_order_id' => $wo->id, 'total_value' => $issued]);

        DeliveryVoucher::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'project_id' => $project->id,
            'status' => DeliveryVoucherStatus::Active,
            'total_value' => $issued,
        ]);

        return $project;
    }

    public function test_page_renders_without_a_selected_operation(): void
    {
        Livewire::test(OperationCostFile::class)->assertOk();
    }

    public function test_page_renders_the_closing_section_for_an_operation(): void
    {
        $project = $this->deliveredOperation();

        Livewire::test(OperationCostFile::class)
            ->set('projectId', $project->id)
            ->assertOk()
            ->assertSee(__('resources.operations_cost.closing.heading'))
            ->assertSee(__('resources.operations_cost.closing.states.open'))
            ->assertSee(__('resources.operations_cost.closing.empty'));
    }

    public function test_closing_action_posts_the_closing(): void
    {
        $project = $this->deliveredOperation(1000);

        Livewire::test(OperationCostFile::class)
            ->set('projectId', $project->id)
            ->callAction('closeCostCenter')
            ->assertHasNoActionErrors();

        $this->assertSame(1000.0, app(CostCenterClosingService::class)->closedValue($project));
    }

    public function test_reversal_action_undoes_a_closing(): void
    {
        $project = $this->deliveredOperation(1000);
        $closing = app(CostCenterClosingService::class)->close($project, null, auth()->user());

        Livewire::test(OperationCostFile::class)
            ->set('projectId', $project->id)
            ->callAction('reverseClosing', [
                'closing_id' => $closing->id,
                'reason' => 'posted to the wrong operation',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(0.0, app(CostCenterClosingService::class)->closedValue($project));
        $this->assertSame(1000.0, app(CostCenterClosingService::class)->unclosedBalance($project));
    }

    public function test_closing_actions_are_hidden_without_the_permission(): void
    {
        $project = $this->deliveredOperation();

        // Reading the cost file is not the same right as posting its closing
        // entry, so this user gets the first permission only.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('operations.view_cost');

        Livewire::actingAs($viewer)
            ->test(OperationCostFile::class)
            ->set('projectId', $project->id)
            ->assertActionHidden('closeCostCenter')
            ->assertActionHidden('reverseClosing');
    }
}
