{{--
    Numbered step flow with arrows between the steps.

    $steps: [ ['title' => '...', 'desc' => '...', 'color' => '#hex'], ... ]
    Set $stack to render the chain vertically (long chains on narrow pages).
--}}
@props([
    'steps' => [],
    'stack' => false,
])

<div class="doc-flow {{ $stack ? 'doc-flow--stack' : '' }}">
    @foreach ($steps as $i => $step)
        <div class="doc-flow__step" style="--step-color: {{ $step['color'] ?? 'var(--brand-500)' }}">
            <span class="doc-flow__no">{{ $i + 1 }}</span>
            <div class="doc-flow__title">{{ $step['title'] }}</div>
            @if (! empty($step['desc']))
                <div class="doc-flow__desc">{{ $step['desc'] }}</div>
            @endif
        </div>

        @if (! $loop->last)
            <div class="doc-flow__arrow" aria-hidden="true">
                <x-doc.icon name="arrow" />
            </div>
        @endif
    @endforeach
</div>
