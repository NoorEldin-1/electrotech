<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Foundation;

use Tests\TestCase;

/**
 * The generated API reference at /api/docs.
 *
 * These files are build output committed to the repository — `scribe:generate`
 * cannot run on the production server because Scribe is a require-dev package
 * and the deploy installs with `--no-dev`. That makes them easy to break
 * silently: nothing fails until someone opens the link.
 */
class ApiDocsTest extends TestCase
{
    public function test_the_docs_page_is_publicly_reachable(): void
    {
        // Public by design — it describes the contract, not the data, and the
        // link is shared with the mobile developer.
        $this->get('/api/docs')
            ->assertOk()
            ->assertSee('Electrotech', false);
    }

    public function test_the_docs_reference_their_assets_by_absolute_path(): void
    {
        $html = (string) $this->get('/api/docs')->getContent();

        // Scribe's static output uses relative "./css/..." paths, which only
        // resolve when the URL ends in a slash or in index.html. At the
        // shareable URL `/api/docs` the browser would resolve them against
        // `/api/` and every asset would 404 — leaving an unstyled page.
        // The published theme overrides the prefix to an absolute path.
        $this->assertStringContainsString('href="/api/docs/css/theme-default.style.css"', $html);
        $this->assertStringContainsString('src="/api/docs/js/', $html);
        $this->assertStringContainsString('src="/api/docs/images/navbar.png"', $html);

        $this->assertStringNotContainsString('"./css/', $html, 'Relative asset paths break /api/docs.');
        $this->assertStringNotContainsString('"./js/', $html, 'Relative asset paths break /api/docs.');
    }

    public function test_the_referenced_asset_files_actually_exist(): void
    {
        foreach ([
            'api/docs/index.html',
            'api/docs/css/theme-default.style.css',
            'api/docs/images/navbar.png',
            // The OpenAPI spec is what lets the Flutter developer generate a
            // typed Dart client instead of hand-writing models.
            'api/docs/openapi.yaml',
            'api/docs/collection.json',
        ] as $relative) {
            $this->assertFileExists(
                public_path($relative),
                "Missing generated docs file: {$relative}. Run `php artisan scribe:generate` and commit public/api/docs/.",
            );
        }
    }

    public function test_the_docs_carry_a_link_preview_card(): void
    {
        $html = (string) $this->get('/api/docs')->getContent();

        // Without these the URL pastes into WhatsApp/X/Slack as a bare link.
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:description"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
    }

    public function test_the_openapi_spec_describes_the_versioned_base_url(): void
    {
        $spec = (string) file_get_contents(public_path('api/docs/openapi.yaml'));

        // A spec pointing at the bare host would generate a client that calls
        // unversioned paths.
        $this->assertStringContainsString('/api/v1', $spec);
        $this->assertStringContainsString('/auth/login', $spec);
    }

    /**
     * The single most important guard in this file.
     *
     * The docs are generated on a developer machine, where APP_URL is
     * `http://localhost:8001`, and then committed and served from production.
     * Anything that derives a URL from APP_URL at generation time freezes
     * localhost into the artifact — the documented Base URL, the OpenAPI
     * `servers` entry, every curl example, and the link-preview card all end
     * up pointing at a host nobody outside the developer's laptop can reach.
     *
     * This already happened once. It is invisible locally (localhost works
     * fine for the person who generated it) and only shows up when someone
     * else opens the link, so a human check is not reliable. Hence a test.
     */
    public function test_the_committed_docs_never_reference_localhost(): void
    {
        foreach ([
            'api/docs/index.html',
            'api/docs/openapi.yaml',
            'api/docs/collection.json',
        ] as $relative) {
            $contents = (string) file_get_contents(public_path($relative));

            foreach (['localhost', '127.0.0.1'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$relative} references {$needle}. The docs are served from production — "
                    .'derive URLs from config("api.docs.public_url"), never config("app.url"), '
                    .'then re-run `php artisan scribe:generate`.',
                );
            }
        }
    }

    public function test_the_docs_point_at_the_production_origin(): void
    {
        $origin = (string) config('api.docs.public_url');
        $html = (string) file_get_contents(public_path('api/docs/index.html'));
        $spec = (string) file_get_contents(public_path('api/docs/openapi.yaml'));

        $this->assertStringContainsString($origin.'/api/v1/auth/login', $html);
        $this->assertStringContainsString($origin, $spec);
    }

    /**
     * The docs are a public page that documents the login endpoint. Using a
     * real account's address as an example there publishes a confirmed-valid
     * username to anyone who opens the link — the attacker then only needs the
     * password. RFC 2606 reserves example.com precisely for this.
     */
    public function test_the_docs_use_reserved_example_addresses_not_real_accounts(): void
    {
        foreach (['api/docs/index.html', 'api/docs/openapi.yaml', 'api/docs/collection.json'] as $relative) {
            $contents = (string) file_get_contents(public_path($relative));

            $this->assertSame(
                [],
                array_values(array_unique(
                    preg_match_all('/[A-Za-z0-9._-]+@electrotech\.com/', $contents, $m) ? $m[0] : [],
                )),
                "{$relative} publishes a real @electrotech.com address. Use @example.com in "
                .'@bodyParam/@response annotations, then re-run `php artisan scribe:generate`.',
            );
        }
    }

    public function test_example_urls_do_not_double_the_version_prefix(): void
    {
        $html = (string) file_get_contents(public_path('api/docs/index.html'));

        // Scribe appends each route's FULL path ("api/v1/meta") to `base_url`.
        // Putting the version prefix in base_url too yields /api/v1/api/v1/meta
        // — example requests that 404 for anyone who copies them.
        $this->assertStringNotContainsString('/api/v1/api/v1', $html);
    }
}
