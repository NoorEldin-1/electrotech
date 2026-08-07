<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Identity;

use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\V1\ApiTestCase;

class UserManagementTest extends ApiTestCase
{
    public function test_index_paginates_with_the_standard_envelope(): void
    {
        User::factory()->count(30)->create();
        $caller = $this->userWith(['users.view']);

        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/users');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.per_page', 25);
        $response->assertJsonCount(25, 'data');
    }

    public function test_index_respects_per_page_and_rejects_going_over_the_cap(): void
    {
        User::factory()->count(5)->create();
        $caller = $this->userWith(['users.view']);

        $this->actingAsApi($caller)->apiGet(self::BASE.'/users?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // A hard ceiling, not a silent clamp — a client asking for 500 rows
        // must learn that it cannot, rather than quietly receiving 100.
        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/users?per_page=500');
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
    }

    public function test_index_supports_search_sort_and_role_filter(): void
    {
        $caller = $this->userWith(['users.view']);
        User::factory()->create(['name' => 'Zainab Ali']);
        $sales = User::factory()->create(['name' => 'Ahmed Sales']);
        $sales->assignRole('Sales');

        $this->actingAsApi($caller)->apiGet(self::BASE.'/users?search=Zainab')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zainab Ali');

        $this->actingAsApi($caller)->apiGet(self::BASE.'/users?filter[role]=Sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ahmed Sales');

        $sorted = $this->actingAsApi($caller)->apiGet(self::BASE.'/users?sort=-name');
        $names = array_column($sorted->json('data'), 'name');
        $this->assertSame($names, collect($names)->sortDesc()->values()->all());
    }

    public function test_an_unknown_filter_is_rejected_rather_than_ignored(): void
    {
        $caller = $this->userWith(['users.view']);

        // Silently ignoring an unknown filter is how a client ships a filter
        // that never worked and nobody notices for months.
        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/users?filter[nonsense]=1');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['filter']]]);
    }

    public function test_an_unknown_sort_or_include_is_rejected(): void
    {
        $caller = $this->userWith(['users.view']);

        $this->actingAsApi($caller)->apiGet(self::BASE.'/users?sort=password')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['sort']]]);

        $this->actingAsApi($caller)->apiGet(self::BASE.'/users?include=tokens')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['include']]]);
    }

    public function test_index_does_not_n_plus_one_on_roles(): void
    {
        $caller = $this->userWith(['users.view']);

        foreach (User::factory()->count(15)->create() as $user) {
            $user->assignRole('Sales');
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAsApi($caller)->apiGet(self::BASE.'/users')->assertOk();
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Eager loading keeps this constant. Without `with('roles')` it grows
        // with the page size — 16 users would add 16 queries.
        $this->assertLessThan(
            15,
            $queries,
            "Listing users issued {$queries} queries; roles are probably not eager-loaded.",
        );
    }

    public function test_show_returns_the_targets_permissions(): void
    {
        $caller = $this->userWith(['users.view']);
        $target = $this->userWith(['projects.view']);

        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/users/'.$target->id);

        $response->assertOk();
        $this->assertItemEnvelope($response);
        $this->assertContains('projects.view', $response->json('data.permissions'));
    }

    public function test_show_returns_the_standard_404_envelope_for_a_missing_user(): void
    {
        $caller = $this->userWith(['users.view']);

        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/users/999999');

        $response->assertStatus(404);
        $this->assertErrorEnvelope($response, 'not_found');
    }

    public function test_store_creates_a_user_with_roles(): void
    {
        $caller = $this->userWith(['users.view', 'users.create']);

        $response = $this->actingAsApi($caller)->apiPost(self::BASE.'/users', [
            'name' => 'Ahmed Hassan',
            'email' => 'ahmed@electrotech.com',
            'password' => 'secret123',
            'roles' => ['Sales'],
        ]);

        $response->assertCreated();
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.email', 'ahmed@electrotech.com');

        $created = User::where('email', 'ahmed@electrotech.com')->firstOrFail();
        $this->assertTrue($created->hasRole('Sales'));
        $this->assertTrue(Hash::check('secret123', $created->password));
    }

    public function test_store_requires_at_least_one_role(): void
    {
        $caller = $this->userWith(['users.view', 'users.create']);

        // A roleless account cannot sign in at all; creating one silently
        // would produce a dead account nobody understands.
        $this->actingAsApi($caller)->apiPost(self::BASE.'/users', [
            'name' => 'Nobody',
            'email' => 'nobody@electrotech.com',
            'password' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['roles']]]);
    }

    public function test_store_rejects_an_unknown_role(): void
    {
        $caller = $this->userWith(['users.view', 'users.create']);

        $this->actingAsApi($caller)->apiPost(self::BASE.'/users', [
            'name' => 'Ahmed',
            'email' => 'a@electrotech.com',
            'password' => 'secret123',
            'roles' => ['Wizard'],
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['roles.0']]]);
    }

    public function test_update_changes_fields_and_roles(): void
    {
        $caller = $this->userWith(['users.view', 'users.edit']);
        $target = User::factory()->create();
        $target->assignRole('Sales');

        $response = $this->actingAsApi($caller)->apiPatch(self::BASE.'/users/'.$target->id, [
            'name' => 'Renamed',
            'roles' => ['Sales_Manager'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Renamed');

        $target->refresh();
        $this->assertTrue($target->hasRole('Sales_Manager'));
        $this->assertFalse($target->hasRole('Sales'));
    }

    public function test_an_admin_password_reset_revokes_the_targets_sessions(): void
    {
        $caller = $this->userWith(['users.view', 'users.edit']);
        $target = $this->userWith(['projects.view']);
        $targetToken = app(ApiTokenService::class)->issue($target, 'Target phone');

        $this->actingAsApi($caller)->apiPatch(self::BASE.'/users/'.$target->id, [
            'password' => 'brandnew123',
        ])->assertOk();

        // Resetting a password while the old token keeps working would defeat
        // the point of the reset.
        $this->withHeader('Authorization', 'Bearer '.$targetToken->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertStatus(401);

        $this->assertTrue(Hash::check('brandnew123', $target->fresh()->password));
    }

    public function test_destroy_removes_the_user_and_their_tokens(): void
    {
        $caller = $this->userWith(['users.view', 'users.delete']);
        $target = $this->userWith(['projects.view']);
        app(ApiTokenService::class)->issue($target, 'Phone');

        $this->actingAsApi($caller)->apiDelete(self::BASE.'/users/'.$target->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::query()
            ->where('tokenable_id', $target->id)
            ->count());
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $caller = $this->userWith(['users.view', 'users.delete']);

        // UserPolicy::delete refuses self-deletion; the API inherits that
        // without restating the rule.
        $response = $this->actingAsApi($caller)->apiDelete(self::BASE.'/users/'.$caller->id);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
        $this->assertDatabaseHas('users', ['id' => $caller->id]);
    }

    /**
     * Each write endpoint must be gated by its own permission, not merely by
     * being authenticated.
     */
    public function test_rbac_denies_every_endpoint_to_a_user_without_the_permission(): void
    {
        $target = User::factory()->create();
        $powerless = $this->userWithoutPermissions();

        $cases = [
            ['GET', self::BASE.'/users'],
            ['GET', self::BASE.'/users/'.$target->id],
            ['POST', self::BASE.'/users'],
            ['PATCH', self::BASE.'/users/'.$target->id],
            ['DELETE', self::BASE.'/users/'.$target->id],
        ];

        foreach ($cases as [$method, $uri]) {
            $response = $this->actingAsApi($powerless)->apiJson($method, $uri, []);

            $this->assertSame(
                403,
                $response->status(),
                "{$method} {$uri} should be 403 for a user without the permission.",
            );
            $this->assertErrorEnvelope($response, 'forbidden');
        }
    }

    public function test_users_view_alone_does_not_grant_write_access(): void
    {
        $reader = $this->userWith(['users.view']);
        $target = User::factory()->create();

        $this->actingAsApi($reader)->apiGet(self::BASE.'/users')->assertOk();

        $this->actingAsApi($reader)->apiPost(self::BASE.'/users', [
            'name' => 'X', 'email' => 'x@e.com', 'password' => 'secret123', 'roles' => ['Sales'],
        ])->assertStatus(403);

        $this->actingAsApi($reader)->apiPatch(self::BASE.'/users/'.$target->id, ['name' => 'Y'])
            ->assertStatus(403);

        $this->actingAsApi($reader)->apiDelete(self::BASE.'/users/'.$target->id)
            ->assertStatus(403);
    }
}
