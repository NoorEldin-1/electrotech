<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * إنهاء استثنائي (Final_Modifications fix wave B / ruling G1): the permission
 * that lets the General Manager end an operation that cannot pass the
 * completion gate — typically an in-flight operation created before the
 * manufacturing / quality / delivery records the gate looks at existed —
 * by giving a mandatory reason, which is recorded in the activity log.
 *
 * Granted to General_Manager and Admin. RoleAndPermissionSeeder never rewrites
 * a role that already exists, so production needs this grant shipped as a
 * migration. It creates the permission itself (the seeder's findOrCreate would
 * too — whichever runs first wins) and is idempotent: running it twice
 * changes nothing.
 */
return new class extends Migration
{
    private const PERMISSION = 'operations.force_complete';

    private const GUARD = 'web';

    /** Roles that receive the permission. */
    private const ROLES = ['Admin', 'General_Manager'];

    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $pivotRole = $columns['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columns['permission_pivot_key'] ?? 'permission_id';

        $permissionId = DB::table($tables['permissions'])
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table($tables['permissions'])->insertGetId([
                'name' => self::PERMISSION,
                'guard_name' => self::GUARD,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = DB::table($tables['roles'])
            ->whereIn('name', self::ROLES)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            $exists = DB::table($tables['role_has_permissions'])
                ->where($pivotPermission, $permissionId)
                ->where($pivotRole, $roleId)
                ->exists();

            if (! $exists) {
                DB::table($tables['role_has_permissions'])->insert([
                    $pivotPermission => $permissionId,
                    $pivotRole => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately irreversible: re-running up() is the only supported
        // direction.
    }
};
