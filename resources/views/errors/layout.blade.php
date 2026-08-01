{{--
    Shared layout for every HTTP error page (E2E report §4.3).

    Before this existed, an invalid route fell through to Laravel's built-in
    page: a bare "404 | NOT FOUND" in English, with no navigation and no way
    back — jarring against an otherwise fully Arabic RTL platform.

    Deliberately standalone, for the same reason the documentation page is:
    an error page must render when things are already broken. So no Filament
    layout, no Livewire, no Vite manifest, no external font — everything is
    inline. It renders correctly even mid-deploy with a cold asset build.

    Locale needs care here. An unmatched URL never reaches a route, so
    neither the `web` group nor the panel's SetLocale middleware runs — the
    app locale is still the raw config default (`en`), which is exactly how
    the report ended up looking at an English 404 inside an Arabic RTL
    platform. So: honour the session locale when a session actually exists,
    and otherwise fall back to Arabic, the platform's working language (the
    same call /documentation makes).

    Expects a single `$code` (the HTTP status). The headline and sentence are
    looked up here, AFTER the locale is settled — translating them in the
    child view would resolve them against the pre-switch locale.
--}}
@php
    $locale = session()->isStarted() && session()->has('locale')
        ? session('locale')
        : 'ar';

    if (! in_array($locale, ['ar', 'en'], true)) {
        $locale = 'ar';
    }

    app()->setLocale($locale);

    $isRtl = $locale === 'ar';

    $title = __("errors.pages.{$code}.title");
    $message = __("errors.pages.{$code}.message");
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $code }} — {{ $title }} | ElectroTech Orwa</title>
    <link rel="icon" href="{{ asset('images/electrotech-logo.jpg') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            --bg: #edeae4;
            --surface: #ffffff;
            --border: rgb(64 48 34 / 0.15);
            --text: #17130f;
            --muted: #5b514a;
            --faint: #8a7f75;
            --brand: #d9723b;
            --brand-dark: #a34823;
        }

        /* Mirrors the admin panel's "Charcoal & Ember" dark tokens so an
           error page never flashes a white sheet at a user who runs dark. */
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #100e0c;
                --surface: #1a1714;
                --border: rgb(245 235 225 / 0.12);
                --text: #f4efe8;
                --muted: #b8afa4;
                --faint: #8a8178;
            }
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background-color: var(--bg);
            color: var(--text);
            font-family: 'Cairo', 'Tajawal', 'Segoe UI', ui-sans-serif, system-ui, sans-serif;
            line-height: 1.7;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            width: 100%;
            max-width: 34rem;
            padding: 2.5rem 2rem;
            text-align: center;
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.875rem;
            box-shadow: 0 4px 12px -2px rgb(60 45 30 / 0.12), 0 2px 4px -1px rgb(60 45 30 / 0.07);
        }

        .logo {
            height: 2.75rem;
            width: auto;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .code {
            font-size: clamp(3.5rem, 14vw, 5rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.02em;
            color: var(--brand);
            margin: 0;
        }

        h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0.75rem 0 0.5rem;
        }

        p {
            margin: 0;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.625rem;
            justify-content: center;
            margin-top: 1.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1.125rem;
            border-radius: 0.5rem;
            border: 1px solid transparent;
            font-size: 0.9375rem;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }

        .btn-primary {
            background-color: var(--brand);
            color: #ffffff;
        }

        .btn-primary:hover { background-color: var(--brand-dark); }

        .btn-secondary {
            border-color: var(--border);
            color: var(--muted);
        }

        .btn-secondary:hover { border-color: var(--brand); color: var(--brand); }

        .ref {
            margin-top: 1.5rem;
            font-size: 0.8125rem;
            color: var(--faint);
        }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="{{ asset('images/electrotech-logo.jpg') }}" alt="ElectroTech Orwa">

        <p class="code">{{ $code }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        <div class="actions">
            <a class="btn btn-primary" href="{{ url('/admin') }}">{{ __('errors.pages.back_to_panel') }}</a>
            <a class="btn btn-secondary" href="{{ url('/documentation') }}">{{ __('errors.pages.open_guide') }}</a>
        </div>

        <p class="ref">{{ __('errors.pages.reference', ['code' => $code]) }}</p>
    </main>
</body>
</html>
