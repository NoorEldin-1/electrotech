{{-- Breadcrumb locator: "ألاقيها فين في النظام؟" --}}
@props(['path' => []])

<div class="doc-where">
    <x-doc.icon name="compass" />
    <span>مكانها:</span>

    @foreach ($path as $crumb)
        <span class="{{ $loop->last ? 'doc-where__last' : '' }}">{{ $crumb }}</span>

        @if (! $loop->last)
            <span class="doc-where__sep" aria-hidden="true">›</span>
        @endif
    @endforeach
</div>
