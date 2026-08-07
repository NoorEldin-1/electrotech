<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Api\ApiRequestId as RequestIdStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns every API request a correlation id, echoed back as `X-Request-Id`
 * and inside every response's `meta`.
 *
 * Production runs with APP_DEBUG=false, so a client never sees a stack trace.
 * The request id is what turns "the app showed an error" into a log line we
 * can actually find. Ask the Flutter client to log it on every failure.
 *
 * An id supplied by the client is honoured (so a mobile-side trace and the
 * server-side trace share a key) but only when it is well-formed — an
 * attacker-controlled string ends up in log files and must not be able to
 * inject newlines or unbounded length.
 */
class ApiRequestId
{
    private const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $supplied = $request->header(self::HEADER);

        $id = is_string($supplied) && $this->isWellFormed($supplied)
            ? $supplied
            : (string) Str::uuid();

        RequestIdStore::set($id);
        $request->headers->set(self::HEADER, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    private function isWellFormed(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id);
    }
}
