<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aggressive Cache-Control for fingerprinted assets so the browser never
 * re-requests them once it has a copy.
 *
 * Vite emits content-hashed filenames under /build/assets/, so the contents
 * at a given URL never change — they are safe to mark `immutable`. On a
 * flaky link this is the difference between Filament's CSS/JS being fetched
 * once and being fetched (partially!) on every SPA navigation.
 *
 * Service-worker registration scripts are versioned via query string; we
 * give them a short TTL so a deploy can roll a new SW out within a minute
 * without an explicit unregister step.
 */
class StaticAssetCacheControl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $path = $request->path();

        if (str_starts_with($path, 'build/assets/')) {
            // Fingerprinted forever-cacheable Vite output.
            $response->headers->set(
                'Cache-Control',
                'public, max-age=31536000, immutable'
            );
            return $response;
        }

        if (str_starts_with($path, 'js/network-resilience.js') || str_starts_with($path, 'js/service-worker.js')) {
            // Resilience scripts: short TTL so a fix rolls out within a
            // minute on every client even without an explicit unregister.
            $response->headers->set('Cache-Control', 'public, max-age=60, must-revalidate');
            return $response;
        }

        if (preg_match('#^(favicon\.ico|images/|fonts/)#', $path) === 1) {
            $response->headers->set('Cache-Control', 'public, max-age=86400');
        }

        return $response;
    }
}
