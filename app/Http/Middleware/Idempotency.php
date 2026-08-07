<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay-safe POST/PUT/PATCH/DELETE.
 *
 * On a weak link, a request can succeed on the server but fail to deliver
 * the response back to the browser. The user retries. Without idempotency
 * that creates TWO Work Orders / Purchase Orders / inventory movements.
 *
 * Protocol (RFC-style):
 *   - Client sends header `Idempotency-Key: <opaque-uuid>` on a write.
 *   - First time we see (user, key): execute, store the (status, headers,
 *     body) in Redis for 24h, return.
 *   - Subsequent calls with the same (user, key): return the cached
 *     response without re-executing.
 *   - A concurrent retry (lock held) gets 409 Conflict so the client knows
 *     to wait + poll instead of starting a parallel job.
 *
 * The client (network-resilience.js) generates a key per logical user
 * action and re-sends it across browser-level retries.
 */
class Idempotency
{
    private const HEADER = 'Idempotency-Key';

    private const TTL_SECONDS = 86400; // 24h

    private const LOCK_TTL_SECONDS = 30; // hard ceiling for any single write

    /**
     * Only intercept verbs that mutate state. GETs are already safe.
     *
     * @var list<string>
     */
    private const VERBS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '' || ! in_array($request->method(), self::VERBS, true)) {
            return $next($request);
        }

        if (! $this->isWellFormedKey($key)) {
            // Don't trust attacker-controlled keys to be cache-safe. Reject
            // rather than silently passing through, so the client can
            // regenerate.
            return response()->json([
                'error' => 'Idempotency-Key must be 8-128 chars of [A-Za-z0-9_-].',
            ], 400);
        }

        $cacheKey = $this->cacheKey($request, $key);
        $lockKey = $cacheKey . ':lock';

        if (($cached = Cache::get($cacheKey)) !== null) {
            return $this->rehydrate($cached);
        }

        // Atomic in-flight marker. `Cache::add` is set-if-not-exists, which
        // gives us a portable lock across Redis (prod) and array (test)
        // stores without the `Cache::lock` owner-token machinery.
        // Atomic in-flight marker. `Cache::add` is set-if-not-exists, which
        // gives us a portable lock across Redis (prod) and array (test)
        // stores without the `Cache::lock` owner-token machinery.
        if (! Cache::add($lockKey, 1, self::LOCK_TTL_SECONDS)) {
            // Another request with the same key is in flight. Tell the
            // client to back off and retry; do NOT start a parallel write.
            return response()->json([
                'error' => 'A previous request with this Idempotency-Key is still in progress.',
                'retry_after' => 2,
            ], 409, ['Retry-After' => '2']);
        }

        try {
            // Double-checked: while we were waiting on the lock another
            // worker may have completed the write and stored its result.
            if (($cached = Cache::get($cacheKey)) !== null) {
                return $this->rehydrate($cached);
            }

            $response = $next($request);

            // Only cache success and client-error (4xx) outcomes. Replaying
            // a 5xx is almost never the right answer — the server failed
            // transiently and a retry on the user's part should try again
            // for real.
            $status = $response->getStatusCode();
            if ($status < 500) {
                $this->store($cacheKey, $response);
            }

            return $response;
        } catch (\Throwable $e) {
            // Don't cache exceptions — a retry should get a fresh attempt.
            Log::warning('Idempotency middleware: handler threw, not caching.', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function isWellFormedKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{8,128}$/', $key) === 1;
    }

    private function cacheKey(Request $request, string $key): string
    {
        return "idempotency:u={$this->callerFingerprint($request)}:m={$request->method()}:k={$key}";
    }

    /**
     * A stable per-caller scope, so one caller can never replay another's
     * cached response by reusing their Idempotency-Key.
     *
     * This middleware is GLOBAL, so it runs before any route middleware has
     * authenticated anyone: at this point `$request->user()` cannot see a
     * bearer token (the `auth:sanctum` guard has not run) and, for the panel,
     * the session has not been started either. It therefore returned 'guest'
     * for effectively every request — collapsing all callers into one shared
     * scope and defeating the isolation this key was supposed to provide.
     *
     * The bearer token is available here, unparsed, on every API request, so
     * we fingerprint that instead. Two tokens of the same user get separate
     * scopes, which is the conservative direction: a genuine client retry
     * reuses the same token and still replays, while a different caller can
     * never read a cached body that was not produced for it.
     *
     * The resolved-user fallback is kept for any caller that reaches this
     * middleware already authenticated.
     */
    private function callerFingerprint(Request $request): string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return 't:'.hash('xxh128', $bearer);
        }

        $userId = $request->user()?->getAuthIdentifier();

        return $userId !== null ? "u:{$userId}" : 'guest';
    }

    /**
     * @return array{status:int, headers:array<string, array<int, string>>, body:string}
     */
    private function snapshot(Response $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            // We deliberately drop Set-Cookie. Replaying session cookies on
            // a retry can confuse the session machinery.
            'headers' => collect($response->headers->allPreserveCase())
                ->except(['Set-Cookie', 'set-cookie'])
                ->all(),
            'body' => (string) $response->getContent(),
        ];
    }

    private function store(string $cacheKey, Response $response): void
    {
        Cache::put($cacheKey, $this->snapshot($response), self::TTL_SECONDS);
    }

    /**
     * @param array{status:int, headers:array<string, array<int, string>>, body:string} $cached
     */
    private function rehydrate(array $cached): Response
    {
        $response = response($cached['body'], $cached['status']);

        foreach ($cached['headers'] as $name => $values) {
            foreach ((array) $values as $value) {
                $response->headers->set($name, $value, false);
            }
        }

        $response->headers->set('Idempotent-Replay', 'true');

        return $response;
    }
}
