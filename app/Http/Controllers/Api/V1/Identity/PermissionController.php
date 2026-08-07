<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Identity;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @group 6. Permissions
 *
 * The read-only permission catalog, grouped exactly as the panel's Roles
 * screen groups it. A Flutter role-editing screen renders this directly.
 *
 * Permissions are defined in code (RoleAndPermissionSeeder) and synced on
 * every deploy, so there is deliberately no create/update/delete here — a
 * permission invented at runtime would be one nothing ever checks.
 */
class PermissionController extends ApiController
{
    /**
     * List permissions
     *
     * Returns every permission in the catalog, grouped by its prefix, with the
     * localized label the panel shows. Bounded (~190 rows, fixed by the
     * seeder) so it is not paginated.
     *
     * Labels honour `Accept-Language`.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":[{"group":"projects","group_label":"Project Management","permissions":[{"name":"projects.view","label":"View Projects"},{"name":"projects.create","label":"Create Project"}]}],"meta":{"request_id":"9f1c...","api_version":"1","count":1}}
     */
    public function index(): JsonResponse
    {
        // Reading the catalog is part of managing roles — the only screen that
        // needs it is the role editor. Gating it on the same permission keeps
        // the system's permission map from being readable by any token holder.
        $this->authorize('viewAny', Role::class);

        $grouped = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission): string => str_contains($permission->name, '.')
                ? explode('.', $permission->name)[0]
                : $permission->name)
            ->map(fn ($permissions, string $group): array => [
                'group' => $group,
                'group_label' => __('resources.roles.groups.'.$group),
                'permissions' => $permissions
                    ->map(fn (Permission $permission): array => [
                        'name' => $permission->name,
                        'label' => __('resources.roles.permissions.'.$permission->name),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return $this->respondCollection($grouped);
    }
}
