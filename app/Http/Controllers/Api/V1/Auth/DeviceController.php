<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Identity\DeviceResource;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 3. Devices
 *
 * The caller's own active sign-in sessions. Every login creates one; this is
 * how a user reviews them and signs a lost device out.
 *
 * Strictly self-scoped: there is no endpoint here to inspect or revoke another
 * user's sessions. An administrator who needs to lock an account out disables
 * the user through the Users endpoints instead.
 */
class DeviceController extends ApiController
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * List my devices
     *
     * Active sessions only — expired tokens are filtered out so the list
     * matches what still works. Newest activity first.
     *
     * The token value itself is never returned; only metadata.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":[{"id":12,"type":"device","name":"Pixel 8 — Warehouse","abilities":["*"],"last_used_at":"2026-08-05T09:12:00+00:00","expires_at":"2026-09-04T09:00:00+00:00","created_at":"2026-08-05T09:00:00+00:00","is_current":true}],"meta":{"request_id":"9f1c...","api_version":"1","count":1}}
     */
    public function index(Request $request): JsonResponse
    {
        $devices = $this->tokens->activeDevices($request->user());

        return $this->respondCollection(
            DeviceResource::collection($devices)->resolve($request),
        );
    }

    /**
     * Revoke a device
     *
     * Signs one session out. Revoking the session you are currently using is
     * allowed and behaves exactly like `POST /auth/logout`.
     *
     * A device id belonging to another user returns 422 rather than 404, so
     * the endpoint cannot be used to probe which token ids exist.
     *
     * @authenticated
     *
     * Documented as `id`, not `device`: this route takes a plain `int` rather
     * than a bound model, so Scribe names the parameter `id` by convention and
     * an annotation keyed on `device` is silently discarded — leaving a faker
     * string in the example URL instead of a number.
     *
     * @urlParam id integer required The device id from `GET /auth/devices`. Example: 12
     *
     * @response 204 scenario="Success" {}
     */
    public function destroy(Request $request, int $device): JsonResponse
    {
        $this->tokens->revokeDevice($request->user(), $device);

        return $this->respondNoContent();
    }
}
