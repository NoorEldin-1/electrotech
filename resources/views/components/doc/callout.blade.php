{{-- Coloured note box. type: info | tip | warn | danger | ok --}}
@props([
    'type' => 'info',
    'title' => null,
])

@php
    $icons = [
        'info' => 'info',
        'tip' => 'bulb',
        'warn' => 'warning',
        'danger' => 'ban',
        'ok' => 'check',
    ];

    $defaults = [
        'info' => 'معلومة',
        'tip' => 'نصيحة',
        'warn' => 'انتبه',
        'danger' => 'تحذير — لا رجعة',
        'ok' => 'النتيجة',
    ];
@endphp

<div class="doc-callout doc-callout--{{ $type }}">
    <div class="doc-callout__icon">
        <x-doc.icon :name="$icons[$type] ?? 'info'" />
    </div>

    <div class="doc-callout__body">
        <div class="doc-callout__title">{{ $title ?? ($defaults[$type] ?? 'معلومة') }}</div>
        <div>{{ $slot }}</div>
    </div>
</div>
