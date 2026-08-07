@php
    use Knuckles\Scribe\Tools\WritingUtils as u;

    /*
     | Serve the page's own CSS/JS/images from an ABSOLUTE path.
     |
     | Scribe's static output references them relatively ("./css/..."), which
     | only resolves when the page is opened at a URL ending in a slash or in
     | "index.html". The shareable URL we hand out is `/api/docs` — with no
     | trailing slash the browser resolves "./css/..." against `/api/` and every
     | asset 404s, leaving an unstyled wall of text.
     |
     | An absolute prefix works at `/api/docs`, `/api/docs/` and
     | `/api/docs/index.html` alike, and does not depend on whether the web
     | server redirects a directory URL. Derived from config so the two cannot
     | drift apart.
     |
     | $assetPathPrefix is also consumed by sidebar.blade.php, which inherits
     | this value through @include.
     */
    $assetPathPrefix = '/'.trim((string) config('api.docs.path'), '/').'/';

    /*
     | Absolute URLs for the canonical link and the link-preview card.
     |
     | These MUST come from the pinned public origin, not config('app.url'):
     | this page is generated on a developer machine and committed, so APP_URL
     | would freeze "http://localhost:8001" into the og:url and og:image of the
     | page served from production — and the preview card would point at a host
     | nobody else can reach.
     */
    $publicOrigin = (string) config('api.docs.public_url');
    $docsUrl = $publicOrigin.'/'.trim((string) config('api.docs.path'), '/');
    $ogImageUrl = $publicOrigin.'/'.ltrim((string) config('api.docs.og_image'), '/');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{!! $metadata['title'] !!}</title>

    {{--
        Link-preview / SEO card.

        This page's URL gets pasted into WhatsApp, Slack and X when handing the
        API over to the mobile developer. Without these tags the preview is a
        bare URL; with them it renders as a titled card. Values come from
        config/api.php so they are editable without touching this view.

        `summary_large_image` is used rather than `summary` because the logo
        reads better wide than as a small square thumbnail.
    --}}
    <meta name="description" content="{{ config('api.docs.og_description') }}">
    <link rel="canonical" href="{{ $docsUrl }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ config('api.docs.og_title') }}">
    <meta property="og:description" content="{{ config('api.docs.og_description') }}">
    <meta property="og:url" content="{{ $docsUrl }}">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:locale" content="ar_EG">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ config('api.docs.og_title') }}">
    <meta name="twitter:description" content="{{ config('api.docs.og_description') }}">
    <meta name="twitter:image" content="{{ $ogImageUrl }}">
    @if(config('api.docs.twitter_site'))
        <meta name="twitter:site" content="{{ config('api.docs.twitter_site') }}">
    @endif

    <link href="https://fonts.googleapis.com/css?family=Open+Sans&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{!! $assetPathPrefix !!}css/theme-default.style.css" media="screen">
    <link rel="stylesheet" href="{!! $assetPathPrefix !!}css/theme-default.print.css" media="print">

    <script src="https://cdn.jsdelivr.net/npm/lodash@4.17.10/lodash.min.js"></script>

    <link rel="stylesheet"
          href="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/styles/obsidian.min.css">
    <script src="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/highlight.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jets/0.14.1/jets.min.js"></script>

@if(isset($metadata['example_languages']))
    <style id="language-style">
        /* starts out as display none and is replaced with js later  */
        @foreach($metadata['example_languages'] as $lang)
            body .content .{{ $lang }}-example code { display: none; }
        @endforeach
    </style>
@endif

@if($tryItOut['enabled'] ?? true)
    <script>
        var tryItOutBaseUrl = "{!! $tryItOut['base_url'] ?? $baseUrl !!}";
        var useCsrf = Boolean({!! $tryItOut['use_csrf'] ?? null !!});
        var csrfUrl = "{!! $tryItOut['csrf_url'] ?? null !!}";
    </script>
    <script src="{{ u::getVersionedAsset($assetPathPrefix.'js/tryitout.js') }}"></script>
@endif

    <script src="{{ u::getVersionedAsset($assetPathPrefix.'js/theme-default.js') }}"></script>

</head>

<body data-languages="{{ json_encode($metadata['example_languages'] ?? []) }}">

@include("scribe::themes.default.sidebar")

<div class="page-wrapper">
    <div class="dark-box"></div>
    <div class="content">
        {!! $intro !!}

        {!! $auth !!}

        @include("scribe::themes.default.groups")

        {!! $append !!}
    </div>
    <div class="dark-box">
        @if(isset($metadata['example_languages']))
            <div class="lang-selector">
                @foreach($metadata['example_languages'] as $name => $lang)
                    @php if (is_numeric($name)) $name = $lang; @endphp
                    <button type="button" class="lang-button" data-language-name="{{$lang}}">{{$name}}</button>
                @endforeach
            </div>
        @endif
    </div>
</div>
</body>
</html>
