<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Filament\Pages\OperationsOverview;
use App\Models\Bom;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function page(string $search = ''): OperationsOverview
    {
        $page = new OperationsOverview();
        $page->search = $search;

        return $page;
    }

    public function test_only_active_operations_are_listed(): void
    {
        $active = Project::factory()->active()->create();
        Project::factory()->draft()->create();
        Project::factory()->tender()->create();
        Project::factory()->completed()->create();

        $operations = $this->page()->getOperations();

        $this->assertCount(1, $operations);
        $this->assertSame($active->id, $operations->first()->id);
    }

    public function test_cross_department_counts_are_loaded(): void
    {
        $project = Project::factory()->active()->create();
        $bom = Bom::factory()->create(['project_id' => $project->id]);
        WorkOrder::factory()->count(2)->create([
            'project_id' => $project->id,
            'bom_id' => $bom->id,
        ]);

        $operation = $this->page()->getOperations()->first();

        $this->assertSame(1, (int) $operation->boms_count);
        $this->assertSame(2, (int) $operation->work_orders_count);
        $this->assertSame(0, (int) $operation->purchase_orders_count);
        $this->assertSame(0, (int) $operation->delivery_vouchers_count);
    }

    public function test_search_filters_by_name_code_and_client(): void
    {
        Project::factory()->active()->create(['name' => 'Substation Alpha', 'code' => 'PRJ-AAA', 'client_name' => 'Acme']);
        Project::factory()->active()->create(['name' => 'Panel Beta', 'code' => 'PRJ-BBB', 'client_name' => 'Globex']);

        $this->assertCount(1, $this->page('Alpha')->getOperations());
        $this->assertCount(1, $this->page('PRJ-BBB')->getOperations());
        $this->assertCount(1, $this->page('Globex')->getOperations());
        $this->assertCount(0, $this->page('Nonexistent')->getOperations());
    }

    public function test_access_is_gated_by_permission(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('General_Manager');
        $this->actingAs($manager);
        $this->assertTrue(OperationsOverview::canAccess());

        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $this->assertFalse(OperationsOverview::canAccess());
    }
}
