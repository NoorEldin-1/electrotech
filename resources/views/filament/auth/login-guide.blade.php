{{--
    Guide link injected under the login form (AUTH_LOGIN_FORM_AFTER).

    The documentation page at /documentation is public, so it is genuinely
    reachable from here — someone who cannot sign in (new hire, auditor,
    client) can still read the manual. That is why this tile lives on the
    login screen and not only inside the authenticated user menu.

    Mirrors the user-menu tile (`.et-guide-link` in theme.css) so the two read
    as the same component, but is re-implemented against the login page's own
    fixed-light `--et-*` tokens: custom-login.css is a standalone stylesheet
    and the panel theme is not loaded on this route.
--}}
<div class="et-login-guide">
    <div class="et-login-guide-divider">
        <span>{{ __('login.guide.divider') }}</span>
    </div>

    <a
        href="{{ url('/documentation') }}"
        target="_blank"
        rel="noopener"
        class="et-login-guide-link"
    >
        <span class="et-login-guide-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
        </span>

        <span class="et-login-guide-text">
            <span class="et-login-guide-title">
                {{ __('login.guide.title') }}
                <span class="et-login-guide-badge">{{ __('login.guide.badge') }}</span>
            </span>
            <span class="et-login-guide-hint">{{ __('login.guide.hint') }}</span>
        </span>

        <svg class="et-login-guide-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
        </svg>
    </a>
</div>
