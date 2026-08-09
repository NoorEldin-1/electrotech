<?php

use App\Exceptions\Api\ApiExceptionRenderer;
use App\Http\Middleware\ApiRequestId;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\ConditionalGet;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\StaticAssetCacheControl;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Network-resilience stack.
        //
        // `withMiddleware` runs before the `config` service is bound,
        // so we cannot call `config()` here — we read env directly via
        // superglobals. The same defaults are documented in
        // config/resilience.php for the rest of the app.

        $readFlag = static function (string $name, bool $defaultLocal, bool $defaultOther): bool {
            $raw = $_ENV[$name] ?? $_SERVER[$name] ?? null;
            if ($raw === null) {
                $g = getenv($name);
                $raw = $g === false ? null : $g;
            }
            if ($raw !== null && $raw !== '') {
                return in_array(strtolower((string) $raw), ['true', '1', 'yes', 'on'], true);
            }
            $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;
            if ($appEnv === null) {
                $g = getenv('APP_ENV');
                $appEnv = $g === false ? 'production' : $g;
            }
            return $appEnv === 'local' ? $defaultLocal : $defaultOther;
        };

        // Order, request flow (outermost → innermost):
        //   1. StaticAssetCacheControl — header-only, cheap, always on
        //   2. Idempotency             — wraps the inner handler so a
        //                                replayed response is served
        //                                straight from the cache
        //   3. CompressResponse        — runs LAST so it sees the final
        //                                body. Defaults OFF in local
        //                                because php-artisan-serve can
        //                                mangle binary bodies on stdout.

        $middleware->append(StaticAssetCacheControl::class);

        if ($readFlag('NETWORK_RESILIENCE_IDEMPOTENCY', true, true)) {
            $middleware->append(Idempotency::class);
        }


        // Bypass CSRF on the ping endpoint. The route is read-only (only
        // writes a timestamp to the user's own session) and is hit from a
        // service-worker heartbeat that can't easily pass a CSRF token
        // through cross-context messaging.
        $middleware->validateCsrfTokens(except: [
            'admin/ping',
        ]);

        // The printable documents (offers, purchase orders, quality sheets,
        // ledger and the financial statements) are plain web routes guarded by
        // Laravel's `auth` middleware, which sends guests to a route NAMED
        // `login`. This application has no such route — the login screen
        // belongs to the Filament panel — so a guest, or anyone whose session
        // expired while a report tab sat open, hit a RouteNotFoundException and
        // got a blank HTTP 500 instead of being asked to sign in again.
        //
        // Deliberately a closure: route() cannot run this early in the
        // bootstrap, and resolving it lazily keeps the panel's own path the
        // single source of truth.
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));

        // ------------------------------------------------------------------
        // REST API stack (routes/api/v1.php). See API_Development_Plan.md §3.
        //
        // The API is stateless: no session, no cookies, no CSRF. The bearer
        // token is the only credential, so Laravel's `api` group stays lean.
        //
        // Order, request flow (outermost → innermost):
        //   1. ApiRequestId       — mint the correlation id first, so every
        //                           later layer (including the exception
        //                           renderer) can quote it
        //   2. ApiSecurityHeaders — unconditional, header-only
        //   3. ForceJsonResponse  — sets Accept: application/json before
        //                           anything can render an HTML error page
        //   4. SetApiLocale       — must run before validation so messages
        //                           come back in the client's language
        //   5. RequireIdempotencyKey — rejects an unprotected write before it
        //                           reaches the controller. The global
        //                           Idempotency middleware (appended above)
        //                           then does the actual replay caching.
        //   6. ConditionalGet     — innermost of these, so it hashes the
        //                           final rendered body
        // ------------------------------------------------------------------
        // Sanctum ships these but does not self-register them in Laravel 11+.
        // `ability:x` passes when the token holds ANY listed ability; a token
        // issued with '*' satisfies every check, which is why the default
        // login token needs no per-route change.
        $middleware->alias([
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        $middleware->api(prepend: [
            ApiRequestId::class,
            ApiSecurityHeaders::class,
            ForceJsonResponse::class,
            SetApiLocale::class,
            RequireIdempotencyKey::class,
            ConditionalGet::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every throwable escaping an `api/*` route is rendered as the error
        // envelope in API_Development_Plan.md §3.3. Returning null from the
        // renderer hands non-API requests back to Laravel, so the Filament
        // panel keeps its HTML error pages untouched.
        $exceptions->render(function (Throwable $e, Request $request) {
            return app(ApiExceptionRenderer::class)->render($e, $request);
        });
    })->create();
