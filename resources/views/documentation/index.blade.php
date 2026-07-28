{{--
    دليل المنصة — public documentation page (/documentation).

    Standalone document: it does NOT extend a Filament layout, does not load
    Livewire/Alpine, and does not depend on the Vite manifest. Everything it
    needs is two files under /public (documentation.css + documentation.js),
    exactly like the login page's custom-login.css. That keeps the page
    bulletproof in production: it renders even if the admin theme is mid-build.

    Arabic + RTL are hard-coded on <html> — this page has no language switcher
    by design.
--}}
@php
    use App\Support\DocumentationOutline;

    $title = 'دليل منصة إلكتروتك عروة — شرح كل شاشة وكل زر في النظام';
    $shortTitle = 'دليل منصة إلكتروتك عروة';
    $description = 'الدليل الرسمي الكامل لمنصة ElectroTech Orwa: شرح مبسّط ومصوَّر لكل قسم وكل شاشة وكل زر — من تسجيل العميل وعرض السعر، مرورًا بالتصنيع والمخازن، وحتى الفواتير وإقفال مركز التكلفة. '
        . $sectionCount . ' قسمًا بالعربي، بدون تسجيل دخول.';

    $canonical = url('/documentation');
    $ogImage = asset('images/documentation-og.png');

    // Cache-bust the static assets on every deploy without touching filenames.
    $assetVersion = '2026.07.28';

    // Built here rather than with @json(): a multi-line array literal inside a
    // Blade directive breaks Blade's own bracket matcher.
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'TechArticle',
        'headline' => $shortTitle,
        'name' => $title,
        'description' => $description,
        'inLanguage' => 'ar',
        'url' => $canonical,
        'image' => $ogImage,
        'author' => ['@type' => 'Organization', 'name' => 'ElectroTech Orwa'],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'ElectroTech Orwa',
            'logo' => ['@type' => 'ImageObject', 'url' => asset('images/electrotech-logo.jpg')],
        ],
        'articleSection' => array_map(
            static fn (array $group): string => $group['label'],
            DocumentationOutline::groups(),
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp
<!doctype html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- ---------- Primary ---------- --}}
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="author" content="ElectroTech Orwa">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="theme-color" content="#f2efe9">
    <meta name="color-scheme" content="light dark">
    <meta name="keywords" content="إلكتروتك عروة, ERP, دليل استخدام, توثيق, نظام إدارة التصنيع, المخازن, المشتريات, المبيعات, الحسابات, busway">

    {{-- ---------- Open Graph (WhatsApp / Facebook / LinkedIn) ---------- --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ElectroTech Orwa · إلكتروتك عروة">
    <meta property="og:locale" content="ar_AR">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:title" content="{{ $shortTitle }} — الدليل الكامل للنظام">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:secure_url" content="{{ $ogImage }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="دليل منصة إلكتروتك عروة — توثيق النظام">

    {{-- ---------- Twitter / X ---------- --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $shortTitle }} — الدليل الكامل للنظام">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    <meta name="twitter:image:alt" content="دليل منصة إلكتروتك عروة">

    {{-- ---------- Icons ---------- --}}
    <link rel="icon" href="{{ asset('images/electrotech-logo.jpg') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/electrotech-logo.jpg') }}">

    {{-- ---------- Fonts ---------- --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap"
        rel="stylesheet"
    >

    {{-- ---------- Styles ---------- --}}
    <link rel="stylesheet" href="{{ asset('css/documentation.css') }}?v={{ $assetVersion }}">

    {{-- Paint the correct theme before first paint so a dark-mode reader
         never gets a white flash. Runs before the body exists. --}}
    <script>
        (function () {
            try {
                var pref = localStorage.getItem('et-docs-theme') || 'system';
                var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>

    {{-- ---------- Structured data ---------- --}}
    <script type="application/ld+json">{!! $jsonLd !!}</script>
</head>

<body>
    <a class="doc-skip" href="#doc-main">تخطَّ إلى المحتوى</a>

    <div class="doc-progress" aria-hidden="true">
        <div class="doc-progress__bar" data-progress></div>
    </div>

    {{-- ================= TOPBAR ================= --}}
    <header class="doc-topbar">
        <a class="doc-topbar__brand" href="{{ url('/documentation') }}">
            <img
                class="doc-topbar__logo"
                src="{{ asset('images/electrotech-logo.jpg') }}"
                alt="شعار إلكتروتك عروة"
                width="38" height="38"
            >
            <span class="doc-topbar__titles">
                <span class="doc-topbar__name">ElectroTech Orwa</span>
                <span class="doc-topbar__kicker">دليل المنصة · التوثيق الرسمي</span>
            </span>
        </a>

        <div class="doc-topbar__spacer"></div>

        <div class="doc-search">
            <x-doc.icon name="search" class="doc-search__icon" />
            <input
                class="doc-search__input"
                type="search"
                data-search
                placeholder="ابحث عن شاشة… (مثال: إذن صرف)"
                aria-label="ابحث في أقسام الدليل"
                autocomplete="off"
                spellcheck="false"
            >
            <span class="doc-search__hint">/</span>
        </div>

        {{-- The light-mode icon starts visible so the button is never blank
             in the window before documentation.js (deferred) runs; the script
             then swaps it to match the resolved theme. --}}
        <button type="button" class="doc-iconbtn" data-theme-toggle aria-label="تبديل المظهر">
            <span data-theme-icon="light"><x-doc.icon name="moon" /></span>
            <span data-theme-icon="dark" hidden><x-doc.icon name="sun" /></span>
        </button>

        <a class="doc-backlink" href="{{ url('/admin') }}">
            <x-doc.icon name="arrow" style="transform:scaleX(-1)" />
            <span>الدخول للنظام</span>
        </a>

        <button type="button" class="doc-iconbtn doc-iconbtn--menu" data-nav-toggle aria-label="فتح قائمة الأقسام">
            <x-doc.icon name="menu" />
        </button>
    </header>

    <div class="doc-backdrop" data-nav-close aria-hidden="true"></div>

    {{-- ================= SHELL ================= --}}
    <div class="doc-shell">

        {{-- ---------- SIDEBAR ---------- --}}
        <nav class="doc-sidebar doc-scroll" data-sidebar aria-label="أقسام الدليل">
            @foreach ($groups as $group)
                <div
                    class="doc-sidebar__group"
                    data-group
                    data-open="true"
                    data-group-label="{{ $group['label'] }}"
                    style="--dept: {{ $group['dept'] }}"
                >
                    <button
                        type="button"
                        class="doc-sidebar__grouphead"
                        data-group-toggle
                        aria-expanded="true"
                    >
                        <span class="doc-sidebar__dot"></span>
                        <span class="doc-sidebar__grouptitle">{{ $group['label'] }}</span>
                        <span class="doc-sidebar__count">{{ count($group['items']) }}</span>
                        <x-doc.icon name="arrowdown" class="doc-sidebar__chev" />
                    </button>

                    <div class="doc-sidebar__links">
                        @foreach ($group['items'] as $item)
                            <a
                                class="doc-sidebar__link"
                                href="#{{ $item['id'] }}"
                                data-jump="{{ $item['id'] }}"
                                data-keywords="{{ $item['kw'] }}"
                            >{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <p class="doc-sidebar__empty">لا يوجد قسم بهذا الاسم. جرّب كلمة أقصر.</p>
        </nav>

        {{-- ---------- MAIN ---------- ---------- --}}
        <main class="doc-main" id="doc-main">
            <div class="doc-container">

                @include('documentation.partials.hero')
                @include('documentation.partials.start')
                @include('documentation.partials.general')
                @include('documentation.partials.sales')
                @include('documentation.partials.pmo')
                @include('documentation.partials.procurement')
                @include('documentation.partials.warehouse')
                @include('documentation.partials.manufacturing')
                @include('documentation.partials.finance')
                @include('documentation.partials.system')
                @include('documentation.partials.appendix')

                <footer class="doc-footer">
                    <img
                        class="doc-footer__logo"
                        src="{{ asset('images/electrotech-logo.jpg') }}"
                        alt=""
                        width="48" height="48"
                    >
                    <p><strong>ElectroTech Orwa</strong> — نظام تخطيط موارد المؤسسات</p>
                    <p>
                        الدليل ده بيوصف النظام زي ما هو شغّال دلوقتي. لو لقيت اختلاف بين الشرح
                        والشاشة، الشاشة هي الأصح — وبلّغ فريق النظام عشان نحدّث الدليل.
                    </p>
                    <p style="margin-top:.75rem">
                        <a href="{{ url('/admin') }}">الدخول إلى النظام ←</a>
                    </p>
                </footer>
            </div>
        </main>
    </div>

    <button type="button" class="doc-top" data-to-top aria-label="العودة إلى أعلى الصفحة">
        <x-doc.icon name="arrowup" />
    </button>

    <script src="{{ asset('js/documentation.js') }}?v={{ $assetVersion }}" defer></script>
</body>
</html>
