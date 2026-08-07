<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\Identity\UserResource;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @group 1. Authentication
 *
 * Sign in, sign out and token rotation.
 *
 * Every other endpoint in this API requires a bearer token obtained here:
 *
 *     Authorization: Bearer 12|xxxxxxxxxxxxxxxx
 *     Accept: application/json
 */
class AuthController extends ApiController
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * Sign in
     *
     * Exchanges credentials for a bearer token. The plain-text token is
     * returned **once and only once** — store it in secure storage
     * (`flutter_secure_storage`), never in shared preferences.
     *
     * Throttled to 5 attempts per minute per email+IP.
     *
     * @unauthenticated
     *
     * @bodyParam email string required The user's email address. Example: warehouse@example.com
     * @bodyParam password string required The user's password. Example: secret123
     * @bodyParam device_name string required A human-recognisable device label, shown in the devices list. Example: Pixel 8 — Warehouse
     * @bodyParam abilities string[] optional Narrow the token to specific modules. Omit for the user's full rights. Example: ["inventory","manufacturing"]
     *
     * @response 200 scenario="Success" {"data":{"token":"12|Xj3kd...","token_type":"Bearer","expires_at":"2026-09-04T09:30:00+00:00","abilities":["*"],"user":{"id":1,"type":"user","name":"System Administrator","email":"admin@example.com","roles":[{"id":1,"name":"Admin"}],"permissions":["projects.view"]}},"meta":{"request_id":"9f1c...","api_version":"1"}}
     * @response 422 scenario="Wrong credentials" {"error":{"code":"validation_failed","message":"The email or password is incorrect.","details":{"email":["The email or password is incorrect."]}},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->with('roles')
            ->where('email', $request->string('email')->toString())
            ->first();

        // One combined check with a single message, so the response cannot be
        // used to enumerate which email addresses exist. Hash::check is still
        // run against a dummy hash when the user is missing, so the timing of
        // "no such user" matches "wrong password".
        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            if ($user === null) {
                Hash::check($request->string('password')->toString(), '$2y$12$'.str_repeat('x', 53));
            }

            throw ValidationException::withMessages([
                'email' => [__('errors.api.invalid_credentials')],
            ]);
        }

        // Mirrors the panel's canAccessPanel() gate: a user with no role has
        // no permissions at all, so a token would be issued that can call
        // nothing. Failing here says why, instead of handing out a token that
        // 403s on every subsequent request.
        if ($user->roles->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => [__('errors.api.account_disabled')],
            ]);
        }

        $token = $this->tokens->issue(
            $user,
            $request->string('device_name')->toString(),
            $request->input('abilities', ['*']),
        );

        activity('api')
            ->causedBy($user)
            ->withProperties([
                'device_name' => $request->string('device_name')->toString(),
                'ip' => $request->ip(),
            ])
            ->log('API token issued');

        return $this->respond([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'abilities' => $token->accessToken->abilities ?? ['*'],
            'user' => (new UserResource($user))->withPermissions()->resolve($request),
        ]);
    }

    /**
     * Sign out (this device)
     *
     * Revokes only the token used to make this call. Other devices stay signed
     * in.
     *
     * @authenticated
     *
     * @response 204 scenario="Success" {}
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return $this->respondNoContent();
    }

    /**
     * Sign out everywhere
     *
     * Revokes every token belonging to the caller, including the current one.
     * Use this for "I lost my phone".
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":{"revoked":3},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $revoked = $this->tokens->revokeAll($request->user());

        return $this->respond(['revoked' => $revoked]);
    }

    /**
     * Rotate the access token
     *
     * Issues a replacement token and revokes the current one atomically. The
     * new token inherits this one's name and abilities — refresh can never
     * widen a token's scope.
     *
     * Call it when the token is within a few days of `expires_at`. There is no
     * separate refresh-token credential: the access token rotates itself.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":{"token":"13|Kd83j...","token_type":"Bearer","expires_at":"2026-10-04T09:30:00+00:00","abilities":["*"]},"meta":{"request_id":"9f1c...","api_version":"1"}}
     */
    public function refresh(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        if (! $current instanceof PersonalAccessToken) {
            // Only reachable if something other than a personal access token
            // authenticated the request (Sanctum's transient token). There is
            // nothing to rotate in that case.
            $this->failValidation('token', 'The current credential is not a rotatable access token.');
        }

        $fresh = $this->tokens->refresh($request->user(), $current);

        return $this->respond([
            'token' => $fresh->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $fresh->accessToken->expires_at?->toIso8601String(),
            'abilities' => $fresh->accessToken->abilities ?? ['*'],
        ]);
    }
}
