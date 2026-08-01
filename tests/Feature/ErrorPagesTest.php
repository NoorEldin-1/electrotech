<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * E2E report §4.3 — an invalid route returned Laravel's built-in
 * "404 | NOT FOUND": unstyled, English-only, and with no way back into the
 * platform, which is jarring inside an Arabic RTL system.
 */
class ErrorPagesTest extends TestCase
{
    public function test_an_unknown_route_renders_the_branded_arabic_404(): void
    {
        $response = $this->get('/this-route-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('الصفحة غير موجودة');
        $response->assertSee('404');
    }

    public function test_the_404_page_is_rtl_and_offers_a_way_back(): void
    {
        $response = $this->get('/admin/definitely-not-a-page');

        $response->assertNotFound();
        $response->assertSee('dir="rtl"', escape: false);
        $response->assertSee(url('/admin'));
        $response->assertSee(url('/documentation'));
    }

    /**
     * The layout must not depend on the Vite manifest or a Filament layout —
     * an error page has to render when the app is already in trouble.
     */
    public function test_the_error_layout_is_self_contained(): void
    {
        $source = file_get_contents(resource_path('views/errors/layout.blade.php'));

        $this->assertStringNotContainsString('@vite', $source);
        $this->assertStringNotContainsString('@livewire', $source);
        $this->assertStringNotContainsString('x-filament', $source);
    }

    #[DataProvider('errorCodes')]
    public function test_every_error_code_has_a_view_and_localized_strings(int $code): void
    {
        $this->assertFileExists(resource_path("views/errors/{$code}.blade.php"));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $this->assertNotSame(
                "errors.pages.{$code}.title",
                __("errors.pages.{$code}.title"),
                "Missing {$locale} title for HTTP {$code}."
            );

            $this->assertNotSame(
                "errors.pages.{$code}.message",
                __("errors.pages.{$code}.message"),
                "Missing {$locale} message for HTTP {$code}."
            );
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorCodes(): array
    {
        return [
            '403' => [403],
            '404' => [404],
            '419' => [419],
            '429' => [429],
            '500' => [500],
            '503' => [503],
        ];
    }
}
