<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\Identity\UserResource;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group 2. Profile
 *
 * The signed-in user's own account: who am I, edit my details, change my
 * password. Managing *other* users lives under "Users" and is permission-gated.
 */
class ProfileController extends ApiController
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * Who am I
     *
     * Returns the caller's profile, roles, and the full flat permission list.
     *
     * Call this right after login and on app resume: the permission array is
     * what the app uses to decide which menu items and buttons to show. It is
     * a UX affordance only — every endpoint re-checks server-side, so a stale
     * client list can never grant access.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"user","name":"System Administrator","email":"admin@example.com","email_verified_at":null,"created_at":"2026-01-01T00:00:00+00:00","updated_at":"2026-01-01T00:00:00+00:00","roles":[{"id":1,"name":"Admin"}],"permissions":["projects.view","projects.create"]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return $this->respond((new UserResource($user))->withPermissions());
    }

    /**
     * Update my profile
     *
     * Changes the caller's own name and/or email. Send only the fields you
     * want changed.
     *
     * @authenticated
     *
     * @bodyParam name string optional New display name. Example: Ahmed Hassan
     * @bodyParam email string optional New email address; must be unique. Example: ahmed@example.com
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"user","name":"Ahmed Hassan","email":"ahmed@example.com","roles":[{"id":2,"name":"Sales"}]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated());
        $user->save();

        return $this->respond((new UserResource($user->load('roles')))->withPermissions());
    }

    /**
     * Change my password
     *
     * Requires the current password. On success **every other device is signed
     * out** — the device making the call keeps working, so the user is not
     * kicked out of the app they are holding.
     *
     * @authenticated
     *
     * @bodyParam current_password string required The password in use right now. Example: oldsecret1
     * @bodyParam password string required The new password: at least 8 characters with letters and numbers. Example: newsecret1
     * @bodyParam password_confirmation string required Must match `password`. Example: newsecret1
     *
     * @response 200 scenario="Success" {"data":{"other_devices_signed_out":2},"meta":{"request_id":"9f1c...","api_version":"1"}}
     * @response 422 scenario="Wrong current password" {"error":{"code":"validation_failed","message":"The current password is incorrect.","details":{"current_password":["The current password is incorrect."]}},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('errors.api.password_incorrect')],
            ]);
        }

        // The `hashed` cast on the model handles the bcrypt.
        $user->password = $request->string('password')->toString();
        $user->save();

        // A password change that leaves old sessions alive gives the user a
        // false sense that they have locked an intruder out. Revoke everything
        // except the caller's own token.
        $signedOut = $this->tokens->revokeAllExceptCurrent(
            $user,
            $user->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken
                ? $user->currentAccessToken()
                : null,
        );

        activity('api')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'other_devices_signed_out' => $signedOut])
            ->log('API password changed');

        return $this->respond(['other_devices_signed_out' => $signedOut]);
    }
}
