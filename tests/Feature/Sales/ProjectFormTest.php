<?php

namespace Tests\Feature\Sales;

use App\Enums\ArrivalMethod;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_project_can_be_created_with_all_new_technical_fields(): void
    {
        $user = User::factory()->create();

        $project = Project::create([
            'name' => 'Acme Substation',
            'code' => 'PRJ-202605-0001',
            'client_name' => 'Acme Corp',
            'consultant_name' => 'Beta Consulting',
            'engineer_name' => 'Eng. Karim',
            'electric_current' => '63A',
            'model' => 'XR-2000',
            'section_type' => 'Main',
            'poles_count' => 4,
            'quantity' => 12,
            'project_location' => 'Cairo',
            'arrival_method' => ArrivalMethod::ConsultantReferral->value,
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'engineer_name' => 'Eng. Karim',
            'electric_current' => '63A',
            'model' => 'XR-2000',
            'poles_count' => 4,
            'quantity' => 12,
            'project_location' => 'Cairo',
            'arrival_method' => ArrivalMethod::ConsultantReferral->value,
        ]);

        $this->assertInstanceOf(ArrivalMethod::class, $project->fresh()->arrival_method);
    }

    public function test_sales_role_can_create_and_update_project_via_policy(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');
        $project = Project::factory()->create();

        $this->assertTrue($user->can('create', Project::class));
        $this->assertTrue($user->can('update', $project));
    }

    public function test_warehouse_role_cannot_create_project(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Warehouse_Manager');

        $this->assertFalse($user->can('create', Project::class));
    }
}
