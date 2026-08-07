<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\V1\ApiTestCase;

class ProfileAndDeviceTest extends ApiTestCase
{
    public function test_me_returns_the_caller_with_roles_and_permissions(): void
    {
        $user = $this->userWith(['projects.view', 'items.view']);

        $response = $this->actingAsApi($user)->apiGet(self::BASE.'/auth/me');

        $response->assertOk();
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.type', 'user');

        $permissions = $response->json('data.permissions');
        $this->assertContains('projects.view', $permissions);
        $this->assertContains('items.view', $permissions);
        $this->assertNotContains('projects.delete', $permissions);
    }

    public function test_me_never_exposes_the_password_hash(): void
    {
        $user = $this->userWith(['projects.view']);

        $response = $this->actingAsApi($user)->apiGet(self::BASE.'/auth/me');

        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.remember_token');
    }

    public function test_profile_update_changes_name_and_email(): void
    {
        $user = $this->userWith(['projects.view']);

        $response = $this->actingAsApi($user)->apiPatch(self::BASE.'/auth/profile', [
            'name' => 'Ahmed Hassan',
            'email' => 'ahmed@electrotech.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Ahmed Hassan');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'ahmed@electrotech.com',
        ]);
    }

    public function test_profile_update_rejects_an_email_already_taken(): void
    {
        User::factory()->create(['email' => 'taken@electrotech.com']);
        $user = $this->userWith(['projects.view']);

        $response = $this->actingAsApi($user)->apiPatch(self::BASE.'/auth/profile', [
            'email' => 'taken@electrotech.com',
        ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_profile_update_cannot_change_roles(): void
    {
        $user = $this->userWith(['projects.view']);

        $this->actingAsApi($user)->apiPatch(self::BASE.'/auth/profile', [
            'name' => 'Still Me',
            'roles' => ['Admin'],
        ])->assertOk();

        // Self-service profile editing must never be a privilege-escalation
        // path: `roles` is not in the FormRequest, so it is discarded.
        $this->assertFalse($user->fresh()->hasRole('Admin'));
    }

    public function test_change_password_requires_the_current_password(): void
    {
        $user = $this->userWith(['projects.view'], ['password' => Hash::make('oldsecret1')]);

        $response = $this->actingAsApi($user)->apiPost(self::BASE.'/auth/change-password', [
            'current_password' => 'wrong-one',
            'password' => 'newsecret1',
            'password_confirmation' => 'newsecret1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.details.current_password.0', __('errors.api.password_incorrect'));
        $this->assertTrue(Hash::check('oldsecret1', $user->fresh()->password));
    }

    public function test_change_password_rejects_a_weak_password(): void
    {
        $user = $this->userWith(['projects.view'], ['password' => Hash::make('oldsecret1')]);

        $this->actingAsApi($user)->apiPost(self::BASE.'/auth/change-password', [
            'current_password' => 'oldsecret1',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_change_password_signs_out_other_devices_but_not_this_one(): void
    {
        $user = $this->userWith(['projects.view'], ['password' => Hash::make('oldsecret1')]);
        $service = app(ApiTokenService::class);

        $other = $service->issue($user, 'Old laptop');
        $current = $service->issue($user, 'This phone');

        $response = $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->apiPost(self::BASE.'/auth/change-password', [
                'current_password' => 'oldsecret1',
                'password' => 'newsecret1',
                'password_confirmation' => 'newsecret1',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.other_devices_signed_out', 1);

        $this->assertTrue(Hash::check('newsecret1', $user->fresh()->password));

        // A password change that leaves old sessions alive would give a false
        // sense of having locked an intruder out.
        $this->withHeader('Authorization', 'Bearer '.$other->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertStatus(401);

        // ...but the device that made the change keeps working.
        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertOk();
    }

    public function test_devices_lists_active_sessions_and_marks_the_current_one(): void
    {
        $user = $this->userWith(['projects.view']);
        $service = app(ApiTokenService::class);
        $service->issue($user, 'Warehouse tablet');
        $current = $service->issue($user, 'Manager phone');

        $response = $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->apiGet(self::BASE.'/auth/devices');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Warehouse tablet', $names);
        $this->assertContains('Manager phone', $names);

        $currentRows = array_values(array_filter(
            $response->json('data'),
            fn (array $row): bool => $row['is_current'] === true,
        ));
        $this->assertCount(1, $currentRows);
        $this->assertSame('Manager phone', $currentRows[0]['name']);
    }

    public function test_devices_never_returns_a_token_value(): void
    {
        $user = $this->userWith(['projects.view']);

        $response = $this->actingAsApi($user)->apiGet(self::BASE.'/auth/devices');

        $response->assertOk();
        foreach ($response->json('data') as $device) {
            $this->assertArrayNotHasKey('token', $device);
            // Not even the stored hash may leave the server.
            $this->assertArrayNotHasKey('plain_text_token', $device);
        }
    }

    public function test_devices_excludes_expired_sessions(): void
    {
        $user = $this->userWith(['projects.view']);
        $service = app(ApiTokenService::class);
        $stale = $service->issue($user, 'Lost tablet');
        $current = $service->issue($user, 'Phone');

        $user->tokens()->whereKey($stale->accessToken->getKey())
            ->update(['expires_at' => now()->subDay()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->apiGet(self::BASE.'/auth/devices');

        // The list must match what still works, or "revoke" buttons appear for
        // sessions that are already dead.
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Phone');
    }

    public function test_a_device_can_be_revoked(): void
    {
        $user = $this->userWith(['projects.view']);
        $service = app(ApiTokenService::class);
        $doomed = $service->issue($user, 'Lost tablet');
        $current = $service->issue($user, 'Phone');

        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->apiDelete(self::BASE.'/auth/devices/'.$doomed->accessToken->getKey())
            ->assertNoContent();

        $this->withHeader('Authorization', 'Bearer '.$doomed->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertStatus(401);
    }

    public function test_a_device_belonging_to_another_user_cannot_be_revoked(): void
    {
        $victim = $this->userWith(['projects.view']);
        $victimToken = app(ApiTokenService::class)->issue($victim, 'Victim phone');

        $attacker = $this->userWith(['projects.view']);

        $response = $this->actingAsApi($attacker)
            ->apiDelete(self::BASE.'/auth/devices/'.$victimToken->accessToken->getKey());

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        // The victim's session is untouched.
        $this->withHeader('Authorization', 'Bearer '.$victimToken->plainTextToken)
            ->apiGet(self::BASE.'/auth/me')
            ->assertOk();
    }
}
