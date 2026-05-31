<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Safe to run on every deploy.
 *
 * Behavior:
 *  - Adds new permissions defined in code to the DB.
 *  - Removes orphaned permissions (in DB but not in code).
 *  - Creates initial roles with default permissions ONLY if they don't exist.
 *  - Does NOT touch role-permission assignments that the Admin manages via UI,
 *    except the Admin role which is always force-synced to all permissions.
 */
class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->syncPermissionCatalog();
        $this->ensureInitialRolesExist();
        $this->grantAdminAllPermissions();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Sync the master permission list with the DB.
     * Adds new permissions and removes orphaned ones.
     * Role-permission assignments for removed permissions are cascade-deleted by Spatie.
     */
    private function syncPermissionCatalog(): void
    {
        $defined = $this->getPermissions();

        foreach ($defined as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Permission::where('guard_name', 'web')
            ->whereNotIn('name', $defined)
            ->delete();
    }

    /**
     * Create the initial set of roles if missing.
     * If a role already exists, its permissions are left untouched (Admin manages via UI).
     */
    private function ensureInitialRolesExist(): void
    {
        foreach ($this->getDefaultRoleDefinitions() as $roleName => $defaultPermissions) {
            $existing = Role::where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if ($existing) {
                continue;
            }

            $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($defaultPermissions);
        }
    }

    /**
     * Admin is the super-admin role: always has every permission in the system.
     */
    private function grantAdminAllPermissions(): void
    {
        $admin = Role::where('name', 'Admin')
            ->where('guard_name', 'web')
            ->first();

        if ($admin) {
            $admin->syncPermissions(Permission::where('guard_name', 'web')->get());
        }
    }

    /**
     * The single source of truth for permissions.
     * Adding a new entry here will surface it in the UI on next deploy.
     * Removing an entry here will delete it from the DB and revoke it from all roles/users.
     */
    private function getPermissions(): array
    {
        return [
            // Project Management
            'projects.view',
            'projects.create',
            'projects.edit',
            'projects.delete',
            'projects.change_status',
            'projects.move_to_tender',
            'projects.move_to_inhand',
            'projects.move_to_active',
            'projects.cancel_to_lost',
            'projects.set_alarm',
            'projects.manager_approve',
            'attachments.upload',
            'attachments.download',
            'attachments.delete',

            // Project Offers (Sales pipeline)
            'project_offers.view',
            'project_offers.create',
            'project_offers.edit',
            'project_offers.delete',

            // Technical Office / BOM
            'items.view',
            'items.create',
            'items.edit',
            'items.delete',
            'boms.view',
            'boms.create',
            'boms.edit',
            'boms.approve',
            'boms.delete',

            // Warehouse / Inventory
            'inventory.view',
            'inventory.manage',
            'inventory.hold',
            'inventory.release',
            'inventory.view_pricing',
            'transactions.view',

            // Procurement
            'purchase_orders.view',
            'purchase_orders.create',
            'purchase_orders.edit',
            'purchase_orders.approve',
            'purchase_orders.receive',
            'purchase_orders.delete',

            // Manufacturing / Work Orders
            'work_orders.view',
            'work_orders.create',
            'work_orders.edit',
            'work_orders.start',
            'work_orders.submit_qa',
            'work_orders.approve_qa',
            'work_orders.complete',
            'work_orders.delete',

            // System Administration
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'roles.manage',
            'activity_log.view',
            'dashboard.view',
        ];
    }

    /**
     * Default permissions applied only when a role is first created.
     * Subsequent edits should be done via the admin UI.
     */
    private function getDefaultRoleDefinitions(): array
    {
        return [
            'Admin' => [], // handled by grantAdminAllPermissions()

            'Sales' => [
                'projects.view',
                'projects.create',
                'projects.edit',
                'projects.change_status',
                'projects.move_to_tender',
                'projects.move_to_inhand',
                'projects.move_to_active',
                'projects.cancel_to_lost',
                'projects.set_alarm',
                'project_offers.view',
                'project_offers.create',
                'project_offers.edit',
                'attachments.upload',
                'attachments.download',
                'dashboard.view',
            ],

            // Sales_Manager: same as Sales plus the manager-approve gate that
            // unlocks the In-Hand → Active transition.
            'Sales_Manager' => [
                'projects.view',
                'projects.create',
                'projects.edit',
                'projects.change_status',
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
                'attachments.upload',
                'attachments.download',
                'attachments.delete',
                'dashboard.view',
            ],

            'Technical_Office' => [
                'projects.view',
                'project_offers.view',
                'items.view',
                'items.create',
                'items.edit',
                'boms.view',
                'boms.create',
                'boms.edit',
                'boms.approve',
                'work_orders.view',
                'work_orders.create',
                'inventory.view',
                'attachments.download',
                'dashboard.view',
            ],

            'Procurement' => [
                'projects.view',
                'project_offers.view',
                'items.view',
                'boms.view',
                'inventory.view',
                'inventory.view_pricing',
                'purchase_orders.view',
                'purchase_orders.create',
                'purchase_orders.edit',
                'purchase_orders.approve',
                'purchase_orders.receive',
                'transactions.view',
                'dashboard.view',
            ],

            'Factory_Manager' => [
                'projects.view',
                'project_offers.view',
                'items.view',
                'boms.view',
                'inventory.view',
                'work_orders.view',
                'work_orders.edit',
                'work_orders.start',
                'work_orders.submit_qa',
                'work_orders.approve_qa',
                'work_orders.complete',
                'transactions.view',
                'dashboard.view',
            ],

            // NOTE: inventory.view_pricing is intentionally excluded
            // per PDF spec: "pricing hidden from warehouse keepers"
            'Warehouse_Manager' => [
                'projects.view',
                'items.view',
                'inventory.view',
                'inventory.manage',
                'inventory.hold',
                'inventory.release',
                'transactions.view',
                'purchase_orders.view',
                'purchase_orders.receive',
                'dashboard.view',
            ],
        ];
    }
}
