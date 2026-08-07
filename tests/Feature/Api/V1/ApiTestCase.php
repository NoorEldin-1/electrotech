<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Http\Api\ApiRequestId;
use App\Models\User;
use App\Services\ApiTokenService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared harness for every API feature test.
 *
 * Two things it guarantees that a plain TestCase does not:
 *
 *  1. Requests carry a real bearer token, not `actingAs()`. Session-based
 *     acting-as would bypass Sanctum entirely, so an ability bug or a token
 *     expiry bug would pass every test and fail in the field.
 *  2. Writes carry an Idempotency-Key by default, because the API requires one
 *     (config('api.require_idempotency_key')). Tests that want to prove the
 *     requirement is enforced opt out explicitly.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const BASE = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        // The request id is held in a static for the exception renderer's
        // benefit; reset it so one test cannot observe another's id.
        ApiRequestId::forget();
    }

    /**
     * A user holding the given permissions, via a throwaway role.
     *
     * Permission-level rather than role-level so a test states exactly which
     * gate it is exercising: "a user with users.view can list users" is a
     * claim about the policy, while "a Sales user can" is a claim about the
     * seeder's defaults, which is a different test.
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $role = \Spatie\Permission\Models\Role::create([
            'name' => 'TestRole_'.Str::random(8),
            'guard_name' => 'web',
        ]);

        if ($permissions !== []) {
            $role->syncPermissions($permissions);
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * A user with a role but no permissions — the right subject for a 403 test.
     * A user with *no* role could not even sign in, which is a different case.
     */
    protected function userWithoutPermissions(): User
    {
        return $this->userWith([]);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user->fresh();
    }

    /**
     * Authenticate subsequent requests as $user with a genuine Sanctum token.
     *
     * @param  list<string>  $abilities
     */
    protected function actingAsApi(User $user, array $abilities = ['*']): static
    {
        $token = app(ApiTokenService::class)->issue($user, 'PHPUnit device', $abilities);

        return $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    /**
     * JSON request with the headers a well-behaved client sends: JSON accept,
     * JSON content type, and a fresh Idempotency-Key on writes.
     *
     * Guards are flushed before every call. Laravel's AuthManager keeps the
     * resolved user on the guard instance for the lifetime of the test, so a
     * second request in the same test would reuse the first request's user
     * even when it carries a different (or revoked, or expired) token. That
     * makes token-revocation and ability bugs invisible to the suite. Real
     * HTTP has no such carry-over; flushing restores the truth.
     */
    protected function apiJson(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $headers);

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && ! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->json($method, $uri, $data, $headers);
    }

    protected function apiGet(string $uri, array $headers = []): TestResponse
    {
        return $this->apiJson('GET', $uri, [], $headers);
    }

    protected function apiPost(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->apiJson('POST', $uri, $data, $headers);
    }

    protected function apiPatch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->apiJson('PATCH', $uri, $data, $headers);
    }

    protected function apiDelete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->apiJson('DELETE', $uri, $data, $headers);
    }

    /**
     * Asserts the success envelope from API_Development_Plan.md §3.2.
     */
    protected function assertItemEnvelope(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'data',
            'meta' => ['request_id', 'api_version'],
        ]);
    }

    /**
     * Asserts the collection envelope, including pagination metadata and links.
     */
    protected function assertPaginatedEnvelope(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'data',
            'meta' => [
                'request_id',
                'api_version',
                'pagination' => ['total', 'count', 'per_page', 'current_page', 'total_pages'],
            ],
            'links' => ['first', 'prev', 'next', 'last'],
        ]);
    }

    /**
     * Asserts the error envelope from §3.3 and, crucially, that `error.code`
     * is the stable machine string the client branches on.
     */
    protected function assertErrorEnvelope(TestResponse $response, string $expectedCode): void
    {
        $response->assertJsonStructure([
            'error' => ['code', 'message'],
            'meta' => ['request_id', 'api_version'],
        ]);

        $response->assertJsonPath('error.code', $expectedCode);
    }
}
