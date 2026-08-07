<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale negotiation for the API.
 *
 * The panel's SetLocale reads the session — the API is stateless, so the
 * client states its language per request via `Accept-Language: ar`. This drives
 * validation messages, service-thrown business messages, and the `label` on
 * every enum object, all from the existing lang/{ar,en} files. No separate API
 * translation tree exists or should exist.
 *
 * Also echoes the resolved locale back as `Content-Language` so a client can
 * confirm what it actually got rather than assuming.
 */
class SetApiLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->negotiate($request);

        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function negotiate(Request $request): string
    {
        $header = (string) $request->header('Accept-Language', '');

        // Accept-Language can be a weighted list ("ar-EG,ar;q=0.9,en;q=0.8").
        // Walk it in order and take the first supported primary subtag; a full
        // q-value sort would be over-engineering for a two-language system.
        foreach (explode(',', $header) as $chunk) {
            $tag = strtolower(trim(explode(';', $chunk)[0]));
            $primary = explode('-', $tag)[0];

            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        return (string) config('app.locale');
    }
}
