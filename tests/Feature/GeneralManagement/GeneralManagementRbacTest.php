<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralManagementRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_general_manager_has_all_general_management_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('General_Manager');

        foreach ([
            'operations.overview',
            'operations.view_cost',
            'operations.activate',
            'operations.complete',
            'operations.hold',
            'operations.reserve',
            'delivery_minutes.view',
            'delivery_minutes.create',
            'delivery_minutes.distribute',
            'financial_claims.view',
            'financial_claims.create',
            'financial_claims.submit',
            'financial_claims.collect',
            // Phase 7
            'operation_payments.view',
            'operation_payments.record',
            'supply_orders_file.view',
            'credit_facilities.view',
            'credit_facilities.manage',
            'installations.view',
            'installations.manage',
            'site_surveys.view',
            'site_surveys.manage',
        ] as $perm) {
            $this->assertTrue($user->can($perm), "General_Manager missing $perm");
        }
    }

    public function test_admin_has_all_general_management_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        foreach ([
            'operations.overview',
            'operations.view_cost',
            'operations.activate',
            'operations.complete',
            'operations.hold',
            'operations.reserve',
            'delivery_minutes.distribute',
            'financial_claims.collect',
        ] as $perm) {
            $this->assertTrue($user->can($perm), "Admin missing $perm");
        }
    }

    public function test_finance_owns_cost_and_claims_but_not_floor_actions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Finance');

        $this->assertTrue($user->can('operations.view_cost'));
        $this->assertTrue($user->can('financial_claims.collect'));

        $this->assertFalse($user->can('operations.overview'));
        $this->assertFalse($user->can('operations.complete'));
        $this->assertFalse($user->can('delivery_minutes.distribute'));
    }

    public function test_sales_manager_can_oversee_and_activate(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales_Manager');

        $this->assertTrue($user->can('operations.overview'));
        $this->assertTrue($user->can('operations.activate'));
        $this->assertTrue($user->can('delivery_minutes.view'));

        $this->assertFalse($user->can('operations.complete'));
        $this->assertFalse($user->can('financial_claims.collect'));
    }

    public function test_factory_manager_runs_the_floor(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Factory_Manager');

        $this->assertTrue($user->can('operations.overview'));
        $this->assertTrue($user->can('operations.complete'));
        $this->assertTrue($user->can('operations.reserve'));

        $this->assertFalse($user->can('operations.view_cost'));
        $this->assertFalse($user->can('financial_claims.create'));
    }

    public function test_unrelated_roles_have_no_general_management_access(): void
    {
        foreach (['Sales', 'Warehouse_Manager'] as $roleName) {
            $user = User::factory()->create();
            $user->assignRole($roleName);

            $this->assertFalse($user->can('operations.overview'), "$roleName should not oversee operations");
            $this->assertFalse($user->can('operations.view_cost'), "$roleName should not see cost");
            $this->assertFalse($user->can('financial_claims.create'), "$roleName should not raise claims");
        }
    }
}
