<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers plus the API version marker on every /api/*
 * response.
 *
 * These are cheap and unconditional. HSTS is emitted only over HTTPS — sending
 * it over plain HTTP is meaningless, and doing so in local development would
 * pin the developer's browser to https://localhost for a year.
 */
class ApiSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-API-Version', (string) config('api.version'));

        // The API returns JSON only. `nosniff` stops a browser from
        // content-sniffing a crafted payload into an executable type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // No API response is ever meant to be framed.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Bearer tokens can land in a URL through client mistakes; do not leak
        // the API path to any third-party host the client subsequently calls.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // `private` keeps every API response out of shared/proxy caches — the
        // payloads are user-scoped. `no-cache` lets the CLIENT keep its own
        // copy but forces it to revalidate before reuse, which is exactly what
        // the ETag layer needs.
        //
        // Deliberately NOT `no-store`: that would forbid the client from
        // holding a copy at all, making `If-None-Match` impossible and killing
        // the conditional-GET bandwidth saving on weak links.
        $response->headers->set('Cache-Control', 'private, no-cache');

        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
