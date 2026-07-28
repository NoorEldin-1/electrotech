{{-- A concrete, story-shaped walkthrough with real numbers. --}}
@props(['title' => 'مثال عملي بالأرقام'])

<div class="doc-example">
    <div class="doc-example__head">
        <x-doc.icon name="beaker" />
        {{ $title }}
    </div>

    <div class="doc-example__body">
        {{ $slot }}
    </div>
</div>
