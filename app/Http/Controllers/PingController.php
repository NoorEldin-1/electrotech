<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cheap connectivity + session-liveness probe.
 *
 * The browser-side resilience script pings this route on a 60s heartbeat
 * (and on `online`/`visibilitychange` events) so it can:
 *
 *   1. Detect "weak" links by measuring round-trip time and surface that
 *      state to the user before they commit a long form.
 *   2. Refresh the session cookie's idle window — otherwise an operator
 *      composing a 20-min Project description would lose their session
 *      mid-write on the slow link.
 *
 * Intentionally minimal: no DB query, no view rendering, just a small JSON
 * blob. The route should bypass CSRF (the handler is read-only and tied to
 * the user's session cookie) and shouldn't allocate work.
 */
class PingController
{
    public function __invoke(Request $request): JsonResponse
    {
        // Touch the session so its idle TTL extends. Reading and rewriting
        // a tiny key is enough — Laravel's session middleware persists the
        // session on response.
        $request->session()->put('_last_ping_at', now()->timestamp);

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ], 200, [
            // Pings are real-time signals, never cache them anywhere.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
