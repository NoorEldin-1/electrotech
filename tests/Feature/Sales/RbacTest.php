<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_sales_role_has_pipeline_permissions_except_manager_approve(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        $this->assertTrue($user->can('projects.move_to_tender'));
        $this->assertTrue($user->can('projects.move_to_inhand'));
        $this->assertTrue($user->can('projects.move_to_active'));
        $this->assertTrue($user->can('projects.cancel_to_lost'));
        $this->assertTrue($user->can('projects.set_alarm'));
        $this->assertFalse($user->can('projects.manager_approve'));
    }

    public function test_sales_manager_role_has_manager_approve(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales_Manager');

        $this->assertTrue($user->can('projects.manager_approve'));
        $this->assertTrue($user->can('projects.move_to_active'));
        $this->assertTrue($user->can('project_offers.delete'));
    }

    public function test_admin_role_has_every_pipeline_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        foreach ([
            'projects.move_to_tender',
            'projects.move_to_inhand',
            'projects.move_to_active',
            'projects.cancel_to_lost',
            'projects.set_alarm',
            'projects.manager_approve',
            'project_offers.view',
            'project_offers.create',
            'project_offers.edit',
            'project_offers.delete',
        ] as $perm) {
            $this->assertTrue($user->can($perm), "Admin missing $perm");
        }
    }

    public function test_warehouse_role_has_no_sales_pipeline_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Warehouse_Manager');

        $this->assertFalse($user->can('projects.move_to_tender'));
        $this->assertFalse($user->can('projects.cancel_to_lost'));
        $this->assertFalse($user->can('projects.manager_approve'));
    }

    public function test_technical_office_can_view_offers_but_not_edit(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Technical_Office');

        $this->assertTrue($user->can('project_offers.view'));
        $this->assertFalse($user->can('project_offers.create'));
        $this->assertFalse($user->can('project_offers.edit'));
    }
}
