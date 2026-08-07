<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes `Idempotency-Key` mandatory on API writes.
 *
 * The replay machinery itself already exists in App\Http\Middleware\Idempotency
 * (built for the weak factory link, and exactly what a mobile client on a
 * patchy connection needs). That middleware is opt-in: it only engages when the
 * client sends the header. For a mobile ERP client that default is backwards —
 * a retried "post issue voucher" without a key means two stock movements.
 *
 * So on /api/* the header is required. A client that forgets it finds out on
 * day one of integration with a clear 400, rather than in production with a
 * duplicated voucher.
 *
 * Toggle with API_REQUIRE_IDEMPOTENCY_KEY=false if a client genuinely cannot
 * comply; the replay protection then simply does not apply to it.
 */
class RequireIdempotencyKey
{
    private const HEADER = 'Idempotency-Key';

    /** @var list<string> */
    private const GUARDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::GUARDED_METHODS, true)) {
            return $next($request);
        }

        if (! config('api.require_idempotency_key')) {
            return $next($request);
        }

        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            return ApiResponse::error(
                'bad_request',
                'This write requires an Idempotency-Key header. Generate one UUID per user action and reuse it across retries.',
                400,
                ['Idempotency-Key' => ['The Idempotency-Key header is required on POST, PUT, PATCH and DELETE.']],
            );
        }

        return $next($request);
    }
}
