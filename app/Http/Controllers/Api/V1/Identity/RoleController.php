<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Identity;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Identity\StoreRoleRequest;
use App\Http\Resources\Api\V1\Identity\RoleResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @group 5. Roles
 *
 * Roles and their attached permissions. Gated by `roles.manage` through the
 * existing RolePolicy — the same gate the panel's Roles screen uses.
 *
 * The Admin role is protected: RolePolicy refuses to delete it, and the
 * deploy-time seeder force-syncs it to every permission in the catalog. Do not
 * try to trim it through this API; the next deploy would undo it.
 */
class RoleController extends ApiController
{
    /**
     * List roles
     *
     * @authenticated
     *
     * @queryParam search string Matches the role name. Example: sales
     * @queryParam sort string Allowed: name, created_at. Prefix `-` for descending. Example: name
     * @queryParam include string Allowed: permissions. Example: permissions
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"type":"role","name":"Admin","guard_name":"web","users_count":1}],"meta":{"request_id":"9f1c...","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"...","prev":null,"next":null,"last":"..."}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = ApiQuery::for($this->baseQuery(), $request)
            ->allowSearch(['name'])
            ->allowSorts(['name', 'created_at'])
            ->allowIncludes(['permissions'])
            ->defaultSort('name')
            ->paginate();

        return $this->respondPaginated(RoleResource::collection($roles));
    }

    /**
     * Show a role
     *
     * Always includes the full permission list attached to the role.
     *
     * @authenticated
     *
     * @urlParam role integer required The role id. Example: 2
     *
     * @response 200 scenario="Success" {"data":{"id":2,"type":"role","name":"Sales","guard_name":"web","users_count":4,"permissions":["projects.view","projects.create"]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role = $this->baseQuery()->whereKey($role->getKey())->firstOrFail();

        return $this->respond(new RoleResource($role->load('permissions')));
    }

    /**
     * Create a role
     *
     * @authenticated
     *
     * @bodyParam name string required Unique role name; letters, digits, spaces, `_` and `-`. Example: Warehouse_Lead
     * @bodyParam permissions string[] required Permission names from `GET /permissions`. Example: ["inventory.view","issue_vouchers.create"]
     *
     * @response 201 scenario="Created" {"data":{"id":9,"type":"role","name":"Warehouse_Lead","guard_name":"web","permissions":["inventory.view","issue_vouchers.create"]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = DB::transaction(function () use ($request): Role {
            $role = Role::create([
                'name' => $request->string('name')->toString(),
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($request->array('permissions'));

            return $role;
        });

        $this->flushPermissionCache();

        return $this->respondCreated(new RoleResource($role->load('permissions')));
    }

    /**
     * Update a role
     *
     * `permissions` **replaces** the role's whole set — send the complete list,
     * not a delta. That mirrors the panel's checkbox grid, where saving writes
     * whatever is ticked.
     *
     * @authenticated
     *
     * @urlParam role integer required The role id. Example: 9
     * @bodyParam name string optional New unique role name. Example: Warehouse_Supervisor
     * @bodyParam permissions string[] optional The complete replacement permission set. Example: ["inventory.view"]
     *
     * @response 200 scenario="Success" {"data":{"id":9,"type":"role","name":"Warehouse_Supervisor","guard_name":"web","permissions":["inventory.view"]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function update(StoreRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        DB::transaction(function () use ($request, $role): void {
            if ($request->has('name')) {
                $role->name = $request->string('name')->toString();
                $role->save();
            }

            if ($request->has('permissions')) {
                $role->syncPermissions($request->array('permissions'));
            }
        });

        $this->flushPermissionCache();

        return $this->respond(new RoleResource($role->fresh()->load('permissions')));
    }

    /**
     * Delete a role
     *
     * RolePolicy refuses to delete the Admin role, so that always returns 403.
     * Users who held the deleted role lose its permissions immediately; a user
     * left with no role at all can no longer sign in.
     *
     * @authenticated
     *
     * @urlParam role integer required The role id. Example: 9
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $role->delete();

        $this->flushPermissionCache();

        return $this->respondNoContent();
    }

    /**
     * Role query carrying a `users_count`, computed WITHOUT Spatie's
     * `Role::users()` relation.
     *
     * That relation resolves its related model via
     * `getModelForGuard(config('auth.defaults.guard'))`. Laravel's
     * `auth:sanctum` middleware calls `Auth::shouldUse('sanctum')`, which
     * rewrites `auth.defaults.guard` to 'sanctum' for the rest of the request
     * — and Sanctum registers that guard with `provider => null`. So during
     * any API request the relation resolves to a null model class and
     * `withCount('users')` fatals.
     *
     * The tempting fix — giving the sanctum guard `provider => users` in
     * config/auth.php — is a trap. Spatie would then resolve User's default
     * guard name to 'sanctum' during API requests, while every permission row
     * is stored under guard 'web', and *all* permission checks would silently
     * start failing.
     *
     * Counting straight off the pivot table sidesteps the guard entirely and
     * is correct in both contexts. Any future endpoint that needs
     * Role::users() or Permission::users() must do the same.
     */
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $pivotTable = config('permission.table_names.model_has_roles');
        $roleKey = app(PermissionRegistrar::class)->pivotRole;

        return Role::query()
            ->select('roles.*')
            ->selectSub(
                DB::table($pivotTable)
                    ->selectRaw('count(*)')
                    ->whereColumn($pivotTable.'.'.$roleKey, 'roles.id')
                    ->where($pivotTable.'.model_type', (new User)->getMorphClass()),
                'users_count',
            );
    }

    /**
     * Spatie caches the whole role/permission graph in Redis. The panel
     * invalidates it through RoleObserver/PermissionObserver on model events;
     * syncPermissions() does not always fire those, so we clear it explicitly.
     * Skipping this would leave every already-authenticated session — web and
     * API alike — enforcing the *old* permission set until the cache expired.
     */
    private function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
