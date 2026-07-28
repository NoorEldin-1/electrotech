<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DocumentationOutline;
use Tests\TestCase;

/**
 * The public documentation page (/documentation).
 *
 * Two things must never regress:
 *
 *   1. It is reachable WITHOUT authentication. The whole point is that the
 *      link can be shared with people who have no account; if it ever slips
 *      behind `auth`, every shared link silently becomes a login screen.
 *   2. The sidebar and the rendered sections stay in sync. The sidebar is
 *      generated from DocumentationOutline, the sections are hand-written
 *      Blade — a drift between them produces dead links in the nav or
 *      unreachable content, neither of which is visible from a 200 response.
 */
class DocumentationPageTest extends TestCase
{
    public function test_it_is_reachable_without_logging_in(): void
    {
        $this->get('/documentation')
            ->assertOk()
            ->assertSee('دليل منصة إلكتروتك عروة', escape: false);
    }

    public function test_the_short_alias_redirects_to_the_canonical_url(): void
    {
        $this->get('/docs')->assertRedirect('/documentation');
    }

    public function test_it_renders_in_arabic_rtl_regardless_of_the_session_locale(): void
    {
        // The page has no language switcher by design; an English session
        // must not flip it into a half-translated state.
        app()->setLocale('en');

        $html = $this->get('/documentation')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="ar" dir="rtl"', $html);
    }

    public function test_it_exposes_link_preview_metadata(): void
    {
        $html = $this->get('/documentation')->assertOk()->getContent();

        foreach ([
            'property="og:title"',
            'property="og:description"',
            'property="og:image"',
            'property="og:image:width" content="1200"',
            'property="og:image:height" content="630"',
            'name="twitter:card" content="summary_large_image"',
            'application/ld+json',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_the_open_graph_image_exists_on_disk(): void
    {
        // A missing file here degrades every shared link to a bare text
        // preview, and nothing in the HTML would reveal it.
        $this->assertFileExists(public_path('images/documentation-og.png'));
    }

    public function test_the_static_assets_exist_on_disk(): void
    {
        // The page deliberately does NOT go through the Vite manifest, so
        // these two files are its only styling/behaviour dependencies.
        $this->assertFileExists(public_path('css/documentation.css'));
        $this->assertFileExists(public_path('js/documentation.js'));
    }

    public function test_every_sidebar_entry_has_a_matching_section(): void
    {
        $html = $this->get('/documentation')->assertOk()->getContent();

        preg_match_all('/id="([a-z0-9-]+)" class="doc-section"/', $html, $matches);
        $rendered = $matches[1];

        $expected = [];
        foreach (DocumentationOutline::groups() as $group) {
            foreach ($group['items'] as $item) {
                $expected[] = $item['id'];
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff($expected, $rendered)),
            'Sidebar links pointing at sections that are not rendered.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($rendered, $expected)),
            'Rendered sections that are missing from the sidebar.',
        );

        $this->assertSame(
            DocumentationOutline::sectionCount(),
            count($rendered),
            'Section count drifted from the outline.',
        );
    }

    /**
     * The login screen is the only place a signed-out person can be, so the
     * guide tile there is the entire discovery path for anyone without an
     * account. A silently dropped render hook would look completely normal.
     */
    public function test_the_login_page_links_to_the_guide(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('et-login-guide-link', $html);
        $this->assertStringContainsString('href="'.url('/documentation').'"', $html);

        // The existing form header hook must keep firing alongside the new one.
        $this->assertStringContainsString('et-login-formhead', $html);
    }

    public function test_the_login_guide_tile_is_translated_in_both_locales(): void
    {
        app()->setLocale('ar');
        $this->assertStringContainsString(
            __('login.guide.title'),
            $this->get('/admin/login')->assertOk()->getContent(),
        );

        app()->setLocale('en');
        $this->assertStringContainsString(
            'Platform Guide',
            $this->get('/admin/login')->assertOk()->getContent(),
        );
    }

    public function test_every_in_page_anchor_points_at_a_real_element(): void
    {
        $html = $this->get('/documentation')->assertOk()->getContent();

        preg_match_all('/href="#([a-z0-9-]+)"/', $html, $hrefs);
        preg_match_all('/\bid="([a-z0-9-]+)"/', $html, $ids);

        $broken = array_values(array_unique(array_diff($hrefs[1], $ids[1])));

        $this->assertSame([], $broken, 'Cross-reference links with no target on the page.');
    }
}
