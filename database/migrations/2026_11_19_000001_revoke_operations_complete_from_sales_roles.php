<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales can no longer end an operation (Final_Modifications Task 2 / R4): an
 * operation ends after manufacturing finish, the quality sheet and the delivery
 * vouchers, driven by the factory manager and the general manager.
 *
 * RoleAndPermissionSeeder never rewrites a role that already exists, so
 * production keeps whatever `operations.complete` the Sales roles were given by
 * hand. This strips it from every `Sales*` role (Sales, Sales_Manager,
 * Sales_Officer, Sales_Head, ...) and from direct grants on the users who hold
 * one of those roles. Idempotent: running it twice, or on a database that never
 * had the grant, changes nothing.
 */
return new class extends Migration
{
    private const PERMISSION = 'operations.complete';

    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $pivotRole = $columns['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columns['permission_pivot_key'] ?? 'permission_id';
        $morphKey = $columns['model_morph_key'] ?? 'model_id';

        $permissionIds = DB::table($tables['permissions'])
            ->where('name', self::PERMISSION)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        $salesRoleIds = DB::table($tables['roles'])
            ->where('name', 'like', 'Sales%')
            ->pluck('id');

        if ($salesRoleIds->isNotEmpty()) {
            DB::table($tables['role_has_permissions'])
                ->whereIn($pivotPermission, $permissionIds)
                ->whereIn($pivotRole, $salesRoleIds)
                ->delete();

            $salesUserIds = DB::table($tables['model_has_roles'])
                ->whereIn($pivotRole, $salesRoleIds)
                ->pluck($morphKey);

            if ($salesUserIds->isNotEmpty()) {
                DB::table($tables['model_has_permissions'])
                    ->whereIn($pivotPermission, $permissionIds)
                    ->whereIn($morphKey, $salesUserIds)
                    ->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately irreversible: re-granting would hand Sales a power the
        // client withdrew.
    }
};
