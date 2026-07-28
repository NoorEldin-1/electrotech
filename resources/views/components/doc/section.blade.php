{{-- One documented module / screen. --}}
@props([
    'id',
    'title',
    'eyebrow' => null,
    'sub' => null,
    'icon' => 'doc',
    'dept' => 'var(--brand-500)',
])

<section id="{{ $id }}" class="doc-section" style="--dept: {{ $dept }}">
    <header class="doc-section__head">
        <div class="doc-section__icon">
            <x-doc.icon :name="$icon" />
        </div>

        <div class="doc-section__titles">
            @if ($eyebrow)
                <div class="doc-section__eyebrow">{{ $eyebrow }}</div>
            @endif

            <h2 class="doc-section__title">
                {{ $title }}
                <button
                    type="button"
                    class="doc-section__anchor"
                    data-copy-link="{{ $id }}"
                    title="نسخ رابط هذا القسم"
                    aria-label="نسخ رابط هذا القسم"
                    style="background:none;border:0;cursor:pointer;font-family:inherit"
                >#</button>
            </h2>

            @if ($sub)
                <p class="doc-section__sub">{{ $sub }}</p>
            @endif
        </div>
    </header>

    {{ $slot }}
</section>
