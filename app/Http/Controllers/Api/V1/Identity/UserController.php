<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Identity;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Identity\StoreUserRequest;
use App\Http\Requests\Api\V1\Identity\UpdateUserRequest;
use App\Http\Resources\Api\V1\Identity\UserResource;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group 4. Users
 *
 * Administration of user accounts. Every endpoint is gated by the same
 * `users.*` permissions and the same UserPolicy the admin panel uses — the API
 * introduces no parallel authorization.
 */
class UserController extends ApiController
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * List users
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches name or email. Example: ahmed
     * @queryParam filter[role] string Filter by role name; comma-separate for OR. Example: Sales,Sales_Manager
     * @queryParam filter[created] string Inclusive date range `from,until`; either side may be omitted. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Comma-separated; prefix with `-` for descending. Allowed: name, email, created_at. Example: -created_at
     * @queryParam include string Comma-separated relations. Allowed: roles. Example: roles
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"type":"user","name":"System Administrator","email":"admin@example.com","roles":[{"id":1,"name":"Admin"}]}],"meta":{"request_id":"9f1c...","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"...","prev":null,"next":null,"last":"..."}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        // `roles` is eager-loaded unconditionally rather than left to
        // `include`: UserResource always renders it, so leaving it optional
        // would mean an N+1 for any client that forgot the parameter.
        $users = ApiQuery::for(User::query()->with('roles'), $request)
            ->allowFilters([
                'role' => fn ($query, $value) => $query->whereHas(
                    'roles',
                    fn ($q) => $q->whereIn('name', explode(',', (string) $value)),
                ),
                'created' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSearch(['name', 'email'])
            ->allowSorts(['name', 'email', 'created_at'])
            ->allowIncludes(['roles'])
            ->defaultSort('name')
            ->paginate();

        return $this->respondPaginated(UserResource::collection($users));
    }

    /**
     * Show a user
     *
     * Includes the target's full resolved permission list, so an administrator
     * screen can show exactly what this account can do.
     *
     * @authenticated
     *
     * @urlParam user integer required The user id. Example: 3
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"user","name":"Ahmed Hassan","email":"ahmed@example.com","roles":[{"id":2,"name":"Sales"}],"permissions":["projects.view"]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return $this->respond(
            (new UserResource($user->load('roles')))->withPermissions(),
        );
    }

    /**
     * Create a user
     *
     * At least one role is required: an account with no role cannot sign in
     * at all, so creating one would silently produce a dead account.
     *
     * @authenticated
     *
     * @bodyParam name string required Display name. Example: Ahmed Hassan
     * @bodyParam email string required Unique email address. Example: ahmed@example.com
     * @bodyParam password string required At least 8 characters with letters and numbers. Example: secret123
     * @bodyParam roles string[] required One or more existing role names. Example: ["Sales"]
     *
     * @response 201 scenario="Created" {"data":{"id":4,"type":"user","name":"Ahmed Hassan","email":"ahmed@example.com","roles":[{"id":2,"name":"Sales"}]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = DB::transaction(function () use ($request): User {
            $user = User::create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
            ]);

            $user->syncRoles($request->array('roles'));

            return $user;
        });

        return $this->respondCreated(new UserResource($user->load('roles')));
    }

    /**
     * Update a user
     *
     * Send only the fields you want changed. Setting `password` revokes every
     * API session belonging to that user — an administrator resetting a
     * password expects the old credentials to stop working immediately.
     *
     * @authenticated
     *
     * @urlParam user integer required The user id. Example: 3
     * @bodyParam name string optional New display name. Example: Ahmed H.
     * @bodyParam email string optional New unique email. Example: ahmed.h@example.com
     * @bodyParam password string optional New password; revokes the user's sessions. Example: newsecret1
     * @bodyParam roles string[] optional Replaces the user's roles entirely. Example: ["Sales_Manager"]
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"user","name":"Ahmed H.","email":"ahmed.h@example.com","roles":[{"id":3,"name":"Sales_Manager"}]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        DB::transaction(function () use ($request, $user): void {
            $user->fill($request->safe()->only(['name', 'email']));

            if ($request->filled('password')) {
                $user->password = $request->string('password')->toString();
            }

            $user->save();

            if ($request->has('roles')) {
                $user->syncRoles($request->array('roles'));
            }

            if ($request->filled('password')) {
                // An administrative password reset must invalidate the old
                // credential everywhere, or the person being locked out keeps
                // working from a token they already hold.
                $this->tokens->revokeAll($user);
            }
        });

        return $this->respond(new UserResource($user->fresh()->load('roles')));
    }

    /**
     * Delete a user
     *
     * Also revokes all of the target's API tokens. UserPolicy forbids deleting
     * your own account, so this always returns 403 when `user` is the caller.
     *
     * @authenticated
     *
     * @urlParam user integer required The user id. Example: 3
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        DB::transaction(function () use ($user): void {
            $this->tokens->revokeAll($user);
            $user->delete();
        });

        return $this->respondNoContent();
    }
}
