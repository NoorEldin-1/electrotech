<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\DeviceToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the sync API by Bearer token issued at enrollment.
 *
 * On success this middleware:
 *   - resolves the DeviceToken and its User
 *   - logs the User in for the duration of the request (so downstream
 *     code can call Auth::user() and Filament-aware policies work)
 *   - attaches the DeviceToken model to the request via attributes,
 *     under the key `sync.device_token`, so controllers can read it
 *     without a second DB hit
 *   - bumps last_used_at + last_used_ip on a queued, async path
 *     (saveQuietly + no observer) so the auth path stays read-only
 *     in steady state
 *
 * On failure: returns a JSON 401 with `error: invalid_token`. No
 * timing-stable rejection — token lookup is a single indexed query,
 * already constant-time within a few microseconds.
 */
final class AuthenticateSyncDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $this->extractBearer($request);

        if ($bearer === null) {
            return $this->reject('missing_token', 'Authorization: Bearer header is required.');
        }

        $token = DeviceToken::findByRawToken($bearer);

        if ($token === null) {
            return $this->reject('invalid_token', 'Token is invalid or revoked.');
        }

        $user = $token->user;
        if ($user === null) {
            return $this->reject('orphaned_token', 'Token has no associated user.');
        }

        Auth::setUser($user);
        $request->attributes->set('sync.device_token', $token);

        // Touch usage AFTER the response is built so a slow DB write
        // never delays the auth path.
        app()->terminating(function () use ($token, $request) {
            try {
                $token->touchUsage($request->ip());
            } catch (\Throwable $e) {
                // Last_used metadata is best-effort. Swallow exceptions
                // so a transient DB hiccup never breaks an authenticated
                // request that already returned a body to the client.
            }
        });

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (! is_string($header) || $header === '') {
            return null;
        }

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    private function reject(string $code, string $message): Response
    {
        return response()->json(
            [
                'error'   => $code,
                'message' => $message,
            ],
            401,
            ['WWW-Authenticate' => 'Bearer realm="electrotech-sync"']
        );
    }
}
