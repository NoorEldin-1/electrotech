<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Foundation;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Structural guarantees about the API route table.
 *
 * These are the rules that are easy to forget when adding module 4's twentieth
 * endpoint at the end of a long day. A forgotten `auth:sanctum` publishes
 * business data to the internet; a forgotten throttle hands out a free
 * denial-of-service; a closure silently breaks `route:cache` on the next
 * deploy. Each is caught here instead.
 *
 * When a genuinely public endpoint is added, list it in PUBLIC_ROUTES with a
 * written reason — that makes "this route needs no token" a deliberate,
 * reviewable decision rather than an oversight.
 */
class RouteConventionsTest extends TestCase
{
    /**
     * Route names that are intentionally reachable without a token.
     *
     * @var array<string, string> name => reason
     */
    private const PUBLIC_ROUTES = [
        'api.v1.meta' => 'Liveness/version probe the app calls before it has a token.',
        'api.v1.auth.login' => 'Where a token comes from.',
    ];

    public function test_every_api_route_is_authenticated_unless_explicitly_public(): void
    {
        $unprotected = [];

        foreach ($this->apiRoutes() as $route) {
            $name = (string) $route->getName();

            if (array_key_exists($name, self::PUBLIC_ROUTES)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true)) {
                $unprotected[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $unprotected, 'API routes missing auth:sanctum: '.implode(', ', $unprotected));
    }

    public function test_every_api_route_carries_a_throttle(): void
    {
        $unlimited = [];

        foreach ($this->apiRoutes() as $route) {
            $hasThrottle = collect($route->gatherMiddleware())
                ->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'));

            if (! $hasThrottle) {
                $unlimited[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $unlimited, 'API routes with no rate limit: '.implode(', ', $unlimited));
    }

    public function test_no_api_route_uses_a_closure(): void
    {
        // `php artisan route:cache` runs on every deploy and refuses to
        // serialize a closure. This would fail the deploy, not the request —
        // which is a much worse place to find out.
        $closures = [];

        foreach ($this->apiRoutes() as $route) {
            if (! is_string($route->getAction('uses'))) {
                $closures[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $closures, 'API routes defined as closures: '.implode(', ', $closures));
    }

    public function test_every_api_route_is_named(): void
    {
        $unnamed = [];

        foreach ($this->apiRoutes() as $route) {
            if (($route->getName() ?? '') === '') {
                $unnamed[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $unnamed, 'Unnamed API routes: '.implode(', ', $unnamed));
    }

    public function test_every_api_route_lives_under_a_version_prefix(): void
    {
        $unversioned = [];

        foreach ($this->apiRoutes() as $route) {
            if (! preg_match('#^api/v\d+(/|$)#', $route->uri())) {
                $unversioned[] = $route->uri();
            }
        }

        // An unversioned endpoint cannot be changed later without breaking
        // whatever is already calling it.
        $this->assertSame([], $unversioned, 'API routes outside a version prefix: '.implode(', ', $unversioned));
    }

    public function test_the_public_route_list_has_no_stale_entries(): void
    {
        $existing = collect($this->apiRoutes())
            ->map(fn (Route $route): string => (string) $route->getName())
            ->all();

        foreach (array_keys(self::PUBLIC_ROUTES) as $name) {
            $this->assertContains(
                $name,
                $existing,
                "PUBLIC_ROUTES lists '{$name}', which no longer exists. Remove it so the exemption list stays meaningful.",
            );
        }
    }

    /**
     * @return list<Route>
     */
    private function apiRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
            // Scribe registers its own docs routes under /docs; they are not
            // part of the API surface and have their own access rules.
            ->reject(fn (Route $route): bool => str_contains($route->uri(), 'docs'))
            ->values()
            ->all();
    }
}
