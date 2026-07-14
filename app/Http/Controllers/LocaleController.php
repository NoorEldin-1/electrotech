<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Illuminate\Http\RedirectResponse;

/**
 * Switches the UI language from the user-menu dropdown.
 *
 * The stand-alone filament-language-switch topbar pill has been retired
 * (see AppServiceProvider). Its switching logic, however, is still the
 * single source of truth: LanguageSwitch::trigger() persists the choice to
 * the session + a forever cookie, fires the LocaleChanged event, and
 * redirects back to the referring page. Reusing it here keeps the language
 * segment inside the user menu behaving identically to the old pill, just
 * driven by a plain GET link instead of a Livewire component.
 */
class LocaleController extends Controller
{
    public function __invoke(string $locale): RedirectResponse
    {
        return LanguageSwitch::trigger(locale: $locale);
    }
}
