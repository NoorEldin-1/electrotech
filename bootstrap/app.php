<?php

use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\StaticAssetCacheControl;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
