<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Api\V1\ApiTestCase;

class AuthenticationTest extends ApiTestCase
{
    public function test_login_returns_a_usable_token_with_the_user_profile(): void
    {
        $user = $this->userWith(['users.view'], [
            'email' => 'warehouse@electrotech.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->apiPost(self::BASE.'/auth/login', [
            'email' => 'warehouse@electrotech.com',
            'password' => 'secret123',
            'device_name' => 'Pixel 8 — Warehouse',
        ]);

        $response->assertOk();
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.token_type', 'Bearer');
        $response->assertJsonPath('data.user.email', 'warehouse@electrotech.com');
        $response->assertJsonPath('data.abilities', ['*']);

        // The permission list is what the app builds its menu from.
        $this->assertContains('users.view', $response->json('data.user.permissions'));

        // The token must actually authenticate, not merely look plausible.
        $token = $response->json('data.token');
        $this->assertIsString($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->apiGet(self::BASE.'/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_login_stores_the_device_name_and_an_expiry(): void
    {
        $this->userWith(['users.view'], [
            'email' => 'a@electrotech.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->apiPost(self::BASE.'/auth/login', [
            'email' => 'a@electrotech.com',
            'password' => 'secret123',
            'device_name' => 'Warehouse Tablet 3',
        ])->assertOk();

        $token = PersonalAccessToken::query()->firstOrFail();

        $this->assertSame('Warehouse Tablet 3', $token->name);
        $this->assertNotNull($token->expires_at, 'Tokens must expire; an immortal token cannot be lost safely.');
    }

    public function test_login_rejects_a_wrong_password_without_revealing_which_field_was_wrong(): void
    {
        $this->userWith(['users.view'], [
            'email' => 'a@electrotech.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->apiPost(self::BASE.'/auth/login', [
            'email' => 'a@electrotech.com',
            'password' => 'wrong-password',
            'device_name' => 'Phone',
        ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_login_gives_the_same_answer_for_an_unknown_email(): void
    {
        $unknown = $this->apiPost(self::BASE.'/auth/login', [
            'email' => 'nobody@electrotech.com',
            'password' => 'whatever1',
            'device_name' => 'Phone',
        ]);

        $unknown->assertStatus(422);

        // Identical wording to the wrong-password case: the endpoint must not
        // be usable to enumerate which addresses have accounts.
        $this->assertSame(
            __('errors.api.invalid_credentials'),
            $unknown->json('error.details.email.0'),
        );
    }

    public function test_login_refuses_an_account_with_no_role(): void
    {
        User::factory()->create([
            'email' => 'orphan@electrotech.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->apiPost(self::BASE.'/auth/login', [
            'email' => 'orphan@electrotech.com',
            'password' => 'secret123',
            'device_name' => 'Phone',
        ]);

        // A roleless account can do nothing; issuing a token would hand out a
        // credential that 403s on every call.
        $response->assertStatus(422);
        $this->assertSame(
            __('errors.api.account_disabled'),
            $response->json('error.details.email.0'),
        );
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_login_validates_the_required_fields(): void
    {
        $response = $this->apiPost(self::BASE.'/auth/login', []);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['email', 'password', 'device_name']]]);
    }

    public function test_login_rejects_an_ability_outside_the_catalog(): void
    {
        $this->userWith(['users.view'], [
            'email' => 'a@electrotech.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->apiPost(self::BASE.'/auth/login', [
            'email' => 'a@electrotech.com',
            'password' => 'secret123',
            'device_name' => 'Phone',
            'abilities' => ['not-a-real-scope'],
        ]);

        // A typo'd scope must not silently produce a token that cannot work.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_protected_routes_reject_a_missing_token(): void
    {
        $response = $this->apiGet(self::BASE.'/auth/me');

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'unauthenticated');
        $response->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_protected_routes_reject_a_garbage_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer 999|not-a-real-token')
            ->apiGet(self::BASE.'/auth/me');

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'unauthenticated');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->userWith(['users.view']);
        $token = app(ApiTokenService::class)->issue($user, 'Old phone');

        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertStatus(401);
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $user = $this->userWith(['users.view']);
        $keep = app(ApiTokenService::class)->issue($user, 'Other device');
        $current = app(ApiTokenService::class)->issue($user, 'This device');

        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->apiPost(self::BASE.'/auth/logout')
            ->assertNoContent();

        $this->assertSame(1, $user->tokens()->count());

        // The other device keeps working.
        $this->withHeader('Authorization', 'Bearer '.$keep->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertOk();
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $user = $this->userWith(['users.view']);
        app(ApiTokenService::class)->issue($user, 'Device A');
        $current = app(ApiTokenService::class)->issue($user, 'Device B');

        $response = $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->apiPost(self::BASE.'/auth/logout-all');

        $response->assertOk();
        $response->assertJsonPath('data.revoked', 2);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_refresh_rotates_the_token_and_kills_the_old_one(): void
    {
        $user = $this->userWith(['users.view']);
        $original = app(ApiTokenService::class)->issue($user, 'Pixel 8', ['identity']);

        $response = $this->withHeader('Authorization', 'Bearer '.$original->plainTextToken)
            ->apiPost(self::BASE.'/auth/refresh');

        $response->assertOk();
        $fresh = $response->json('data.token');

        $this->assertNotSame($original->plainTextToken, $fresh);

        // Abilities are inherited: refresh must never widen a token's scope.
        $response->assertJsonPath('data.abilities', ['identity']);

        // The new one works...
        $this->withHeader('Authorization', 'Bearer '.$fresh)
            ->apiGet(self::BASE.'/auth/me')
            ->assertOk();

        // ...and the old one does not.
        $this->withHeader('Authorization', 'Bearer '.$original->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertStatus(401);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_token_abilities_narrow_what_the_token_may_reach(): void
    {
        $user = $this->userWith(['users.view']);

        // The user MAY list users, but this token was not issued for identity.
        $response = $this->actingAsApi($user, ['inventory'])
            ->apiGet(self::BASE.'/users');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');

        // Self-scoped endpoints stay reachable regardless of module scope.
        $this->actingAsApi($user, ['inventory'])
            ->apiGet(self::BASE.'/auth/me')
            ->assertOk();
    }

    public function test_token_abilities_can_never_widen_beyond_the_users_permissions(): void
    {
        // A token scoped to `identity` on a user who lacks users.view still
        // cannot list users: the two gates are an intersection, not an OR.
        $user = $this->userWithoutPermissions();

        $response = $this->actingAsApi($user, ['identity'])
            ->apiGet(self::BASE.'/users');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_the_oldest_token_is_evicted_once_the_per_user_cap_is_reached(): void
    {
        config(['api.tokens.max_per_user' => 3]);

        $user = $this->userWith(['users.view']);
        $service = app(ApiTokenService::class);

        $first = $service->issue($user, 'Device 1');
        $service->issue($user, 'Device 2');
        $service->issue($user, 'Device 3');
        $service->issue($user, 'Device 4');

        $this->assertSame(3, $user->tokens()->count());

        // Refusing the login instead would lock a user out of their own
        // account via tablets they no longer own.
        $this->withHeader('Authorization', 'Bearer '.$first->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertStatus(401);
    }
}
