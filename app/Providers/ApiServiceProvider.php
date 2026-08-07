<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Api\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the REST API needs at boot: rate limiters and HTTPS enforcement.
 *
 * Kept out of AppServiceProvider so the API layer stays a separable concern —
 * the panel boots identically whether or not the API is enabled.
 */
class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->forceHttpsInProduction();
        $this->registerRateLimiters();
    }

    /**
     * Bearer tokens must never travel in clear text. In production every URL
     * the framework generates (including the pagination `links` in a
     * collection envelope) is https, so a client cannot be walked onto plain
     * HTTP by following our own links.
     *
     * Not applied in local/testing: `php artisan serve` has no TLS and it
     * would break every feature test.
     */
    private function forceHttpsInProduction(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Limits documented in API_Development_Plan.md §3.9 and configurable via
     * config/api.php, so a real-world spike can be absorbed by an .env change
     * rather than a deploy.
     */
    private function registerRateLimiters(): void
    {
        // Login and other credential-checking endpoints. Keyed on email+IP
        // rather than IP alone: keying on the email alone would let anyone
        // lock a known user out of their own account by burning the quota,
        // and IP alone is useless behind a shared factory NAT.
        RateLimiter::for('api-auth', function (Request $request) {
            $email = (string) $request->input('email', '');

            return [
                Limit::perMinute((int) config('api.rate_limits.auth'))
                    ->by('api-auth:'.mb_strtolower($email).'|'.$request->ip())
                    ->response($this->tooManyRequests(...)),
            ];
        });

        RateLimiter::for('api-read', fn (Request $request) => Limit::perMinute(
            (int) config('api.rate_limits.read')
        )->by($this->identify($request, 'read'))->response($this->tooManyRequests(...)));

        RateLimiter::for('api-write', fn (Request $request) => Limit::perMinute(
            (int) config('api.rate_limits.write')
        )->by($this->identify($request, 'write'))->response($this->tooManyRequests(...)));

        // Reports and PDF streams do real work — a general ledger over a wide
        // date range scans a lot of rows. A tight limit here protects the
        // database from a client that re-runs a report on every screen focus.
        RateLimiter::for('api-reports', fn (Request $request) => Limit::perMinute(
            (int) config('api.rate_limits.reports')
        )->by($this->identify($request, 'reports'))->response($this->tooManyRequests(...)));
    }

    /**
     * Authenticated callers are limited per user, so one user on a bad network
     * cannot exhaust the whole factory's quota from a shared public IP.
     * Guests fall back to IP.
     */
    private function identify(Request $request, string $bucket): string
    {
        $user = $request->user();

        return $user !== null
            ? "api-{$bucket}:user:{$user->getAuthIdentifier()}"
            : "api-{$bucket}:ip:{$request->ip()}";
    }

    /**
     * Laravel's default throttle response is a bare JSON body that does not
     * match our error envelope. Route it through ApiResponse so a 429 looks
     * exactly like every other error the client handles.
     */
    private function tooManyRequests(Request $request, array $headers = [])
    {
        return ApiResponse::error(
            'rate_limited',
            __('errors.api.rate_limited'),
            429,
            headers: $headers,
        );
    }
}
