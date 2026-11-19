<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inventory valuation report (Final_Modifications Task 8).
 *
 * `RoleAndPermissionSeeder` creates the new `reports.inventory_valuation`
 * permission on every deploy, but never touches the permissions of a role that
 * already exists, so production's Finance and General_Manager roles would not
 * receive it. This creates the permission (so the grant does not depend on the
 * seeder having run first) and grants it to Finance, General_Manager and
 * Admin. Idempotent: roles that do not exist are skipped, and running it twice
 * changes nothing.
 */
return new class extends Migration
{
    private const PERMISSION = 'reports.inventory_valuation';

    /** @var list<string> */
    private const ROLES = ['Finance', 'General_Manager', 'Admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        foreach (self::ROLES as $name) {
            $role = Role::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            if ($role !== null && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately irreversible: the seeder owns the permission from here
        // on, and revoking it would hide a report finance already uses.
    }
};
