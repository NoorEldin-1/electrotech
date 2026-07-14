{{--
    Premium brand showcase panel — occupies the inline-start half of the login
    screen on desktop (hidden < 1024px). Pure decorative layers are driven by
    public/css/custom-login.css. Copy comes from lang/{locale}/login.php.
--}}
<aside class="et-login-showcase" aria-hidden="true">
    {{-- Decorative background stack (skinned entirely in CSS) --}}
    <div class="et-showcase-aurora"></div>
    <div class="et-showcase-grid"></div>
    <div class="et-showcase-orb et-showcase-orb--1"></div>
    <div class="et-showcase-orb et-showcase-orb--2"></div>
    <div class="et-showcase-grain"></div>

    <div class="et-showcase-inner">
        {{-- Brand lockup --}}
        <div class="et-showcase-brand">
            <span class="et-showcase-logo">
                <img src="{{ asset('images/electrotech-logo.jpg') }}" alt="ElectroTech Orwa" />
            </span>
            <span class="et-showcase-wordmark">ElectroTech&nbsp;Orwa</span>
        </div>

        {{-- Tagline --}}
        <h1 class="et-showcase-tagline">{{ __('login.tagline') }}</h1>

        {{-- Feature list --}}
        <ul class="et-showcase-features">
            @php
                $features = [
                    'integrated' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />',
                    'realtime'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />',
                    'secure'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 5.591-3.824 10.29-9 11.622C6.824 22.29 3 17.591 3 12V5.25c0-.621.504-1.125 1.125-1.125A9.75 9.75 0 0012 2.71a9.75 9.75 0 007.875 1.415c.621 0 1.125.504 1.125 1.125V12z" />',
                ];
            @endphp

            @foreach ($features as $key => $iconPath)
                <li class="et-showcase-feature">
                    <span class="et-showcase-feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            {!! $iconPath !!}
                        </svg>
                    </span>
                    <span class="et-showcase-feature-text">
                        <span class="et-showcase-feature-title">{{ __("login.features.{$key}.title") }}</span>
                        <span class="et-showcase-feature-desc">{{ __("login.features.{$key}.desc") }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        {{-- Footer --}}
        <div class="et-showcase-footer">
            <span>&copy; {{ date('Y') }}</span>
            <span>{{ __('login.footer') }}</span>
        </div>
    </div>
</aside>
