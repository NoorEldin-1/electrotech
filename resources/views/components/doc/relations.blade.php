{{--
    "How this module connects to the rest of the system".

    $items: [ ['dir' => 'in'|'out'|'both', 'target' => 'أوامر التصنيع',
               'href' => 'work-orders', 'why' => '...'], ... ]
--}}
@props([
    'title' => 'علاقة هذه الشاشة بباقي أجزاء النظام',
    'items' => [],
])

@php
    $arrows = [
        'in' => '⟵ يستقبل من',
        'out' => 'يُغذّي ⟶',
        'both' => '⟷ تبادل',
    ];
@endphp

<div class="doc-rel">
    <div class="doc-rel__head">
        <x-doc.icon name="link" />
        {{ $title }}
    </div>

    <div class="doc-rel__list">
        @foreach ($items as $item)
            <div class="doc-rel__item">
                <span class="doc-rel__arrow">{{ $arrows[$item['dir'] ?? 'out'] }}</span>

                @if (! empty($item['href']))
                    <a class="doc-rel__target" href="#{{ $item['href'] }}">{{ $item['target'] }}</a>
                @else
                    <span class="doc-rel__target">{{ $item['target'] }}</span>
                @endif

                <span class="doc-rel__why">{!! $item['why'] !!}</span>
            </div>
        @endforeach
    </div>
</div>
