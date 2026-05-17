<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = $this->getPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->createRolesWithPermissions();
    }

    /**
     * Returns a flat list of all permissions grouped by module.
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
            'attachments.upload',
            'attachments.download',
            'attachments.delete',

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

    private function createRolesWithPermissions(): void
    {
        // Admin: Full system access (super-admin pattern)
        $admin = Role::findOrCreate('Admin', 'web');
        $admin->givePermissionTo(Permission::all());

        // Sales: Project initiation and CRM
        $sales = Role::findOrCreate('Sales', 'web');
        $sales->givePermissionTo([
            'projects.view',
            'projects.create',
            'projects.edit',
            'projects.change_status',
            'attachments.upload',
            'attachments.download',
            'dashboard.view',
        ]);

        // Technical Office: BOM & Engineering
        $technicalOffice = Role::findOrCreate('Technical_Office', 'web');
        $technicalOffice->givePermissionTo([
            'projects.view',
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
        ]);

        // Procurement: Purchase Order management
        $procurement = Role::findOrCreate('Procurement', 'web');
        $procurement->givePermissionTo([
            'projects.view',
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
        ]);

        // Factory Manager: Manufacturing & QA oversight
        $factoryManager = Role::findOrCreate('Factory_Manager', 'web');
        $factoryManager->givePermissionTo([
            'projects.view',
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
        ]);

        // Warehouse Manager: Inventory & stock control
        // NOTE: inventory.view_pricing is intentionally excluded
        // per PDF spec: "pricing hidden from warehouse keepers"
        $warehouseManager = Role::findOrCreate('Warehouse_Manager', 'web');
        $warehouseManager->givePermissionTo([
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
        ]);
    }
}
