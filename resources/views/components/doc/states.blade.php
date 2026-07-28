{{--
    State machine strip: the statuses a record moves through, with the
    trigger written on each arrow.

    $states: [ ['label' => 'مسودة', 'tone' => 'neutral', 'note' => 'draft'], ... ]
    $arrows: labels shown between states (index i = between state i and i+1)
    tone: neutral | info | warn | ok | danger | tip
--}}
@props([
    'title' => 'الحالات ومسار التغيير',
    'states' => [],
    'arrows' => [],
    'legend' => null,
])

<div class="doc-states">
    <div class="doc-states__head">
        <x-doc.icon name="route" />
        {{ $title }}
    </div>

    <div class="doc-states__track">
        @foreach ($states as $i => $state)
            <span class="doc-state doc-state--{{ $state['tone'] ?? 'neutral' }}">
                {{ $state['label'] }}
                @if (! empty($state['note']))
                    <small>{{ $state['note'] }}</small>
                @endif
            </span>

            @if (! $loop->last)
                <span class="doc-states__arrow" aria-hidden="true">
                    <x-doc.icon name="arrow" />
                    @if (! empty($arrows[$i]))
                        <span>{{ $arrows[$i] }}</span>
                    @endif
                </span>
            @endif
        @endforeach
    </div>

    @if ($legend)
        <div class="doc-states__legend">{{ $legend }}</div>
    @endif
</div>
