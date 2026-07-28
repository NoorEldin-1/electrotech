{{-- Compact key/value strip for at-a-glance module facts. --}}
@props(['items' => []])

<div class="doc-facts">
    @foreach ($items as $key => $value)
        <div class="doc-fact">
            <span class="doc-fact__k">{{ $key }}</span>
            <span class="doc-fact__v">{!! $value !!}</span>
        </div>
    @endforeach
</div>
