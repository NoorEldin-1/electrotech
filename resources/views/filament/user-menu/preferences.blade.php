{{--
    Language segment for the user-menu dropdown.

    Rendered via the panels::user-menu.profile.after render hook so it sits
    directly above Filament's built-in theme switcher — the two form a single
    "Preferences" zone (language + appearance) inside the avatar dropdown,
    replacing the old stand-alone topbar language pill.

    Each option is a plain GET link to the admin.locale route, which delegates
    to LanguageSwitch::trigger() (session + forever cookie + LocaleChanged) and
    redirects back — a full navigation so the locale + RTL direction re-apply.
--}}
@php
    $locales = [
        'en' => 'English',
        'ar' => 'العربية',
    ];
    $current = app()->getLocale();
@endphp

<div class="et-prefs">
    <span class="et-prefs-label">{{ __('navigation.user_menu.language') }}</span>

    <div
        class="et-segment"
        role="group"
        aria-label="{{ __('navigation.user_menu.language') }}"
    >
        @foreach ($locales as $code => $label)
            <a
                href="{{ route('admin.locale', $code) }}"
                @class(['et-segment-btn', 'et-active' => $current === $code])
                @if ($current === $code) aria-current="true" @endif
            >
                <span class="et-segment-code">{{ \Illuminate\Support\Str::upper($code) }}</span>
                <span class="et-segment-name">{{ $label }}</span>
            </a>
        @endforeach
    </div>
</div>
