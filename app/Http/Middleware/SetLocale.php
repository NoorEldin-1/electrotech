<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to set the application locale from the session.
 *
 * Works in conjunction with the filament-language-switch package,
 * which stores the selected locale in the session. This middleware
 * reads that value and applies it to the application on every request.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale', config('app.locale'));
        $supportedLocales = ['en', 'ar'];

        if (in_array($locale, $supportedLocales, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
