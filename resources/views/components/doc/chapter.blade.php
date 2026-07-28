{{-- Department divider: opens a new chapter of the documentation. --}}
@props([
    'id',
    'no' => '',
    'title',
    'sub' => null,
    'dept' => 'var(--brand-500)',
    'chips' => [],
])

<div id="{{ $id }}" class="doc-chapter" style="--dept: {{ $dept }}">
    @if ($no !== '')
        <span class="doc-chapter__no">{{ $no }}</span>
    @endif

    <h2 class="doc-chapter__title">{{ $title }}</h2>

    @if ($sub)
        <p class="doc-chapter__sub">{{ $sub }}</p>
    @endif

    @if (count($chips))
        <div class="doc-chapter__chips">
            @foreach ($chips as $href => $label)
                <a class="doc-chip" href="#{{ $href }}">
                    <x-doc.icon name="arrow" style="width:.8rem;height:.8rem;transform:scaleX(-1)" />
                    {{ $label }}
                </a>
            @endforeach
        </div>
    @endif

    {{ $slot }}
</div>
