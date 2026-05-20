<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sync;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Enrolment flow: a logged-in operator (web session) registers their
 * device and receives a bearer token to use for the sync API.
 *
 * Auth model: this endpoint runs under the regular Filament web guard,
 * NOT the AuthenticateSyncDevice middleware — because there is no token
 * yet to authenticate with. The user must be signed in to the admin
 * panel; mounting this on a dedicated route prefix is handled in
 * routes/web.php.
 *
 * One device → at most one active token. Re-enrolling the same
 * device_id revokes the previous token before issuing a new one, so a
 * misbehaving device caught by IT can be wiped and re-enrolled without
 * leaving a dangling credential.
 */
final class EnrollController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id'   => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = Auth::user();
        if ($user === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // Revoke any prior active token for the same (user, device).
        DeviceToken::query()
            ->where('user_id', $user->id)
            ->where('device_id', $data['device_id'])
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        [$token, $raw] = DeviceToken::issue(
            $user,
            $data['device_id'],
            $data['device_name'] ?? null,
        );

        // The raw token is the ONLY copy the client will ever see.
        // The server keeps the SHA-256 hash. The response also includes
        // the user profile + the pull page size config so the client
        // can configure itself without a second round trip.
        return response()->json([
            'token'     => $raw,
            'device_id' => $token->device_id,
            'user'      => [
                'id'   => $user->id,
                'name' => $user->name,
                'email'=> $user->email,
            ],
            'config' => [
                'pull_page_size'           => (int) config('sync.pull.page_size'),
                'max_operations_per_batch' => (int) config('sync.push.max_operations_per_batch'),
                'models'                   => array_keys(config('sync.models')),
            ],
        ], 201);
    }
}
