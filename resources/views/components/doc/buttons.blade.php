{{--
    Button reference: for every button on a screen — what it does, when it
    shows up, what happens after, and who is allowed to press it.

    $items: [[
        'name'   => 'ترحيل',
        'icon'   => 'check',
        'color'  => '#hex',       // optional accent
        'pill'   => 'لا رجعة',    // optional badge
        'does'   => 'الزر بيعمل إيه',
        'when'   => 'بيظهر إمتى',
        'after'  => 'بيحصل إيه بعد الضغط',
        'perm'   => 'الصلاحية المطلوبة',
    ], ...]
--}}
@props([
    'title' => 'الأزرار الموجودة في الشاشة وشرح كل واحد',
    'items' => [],
])

<div class="doc-card">
    <div class="doc-card__title">
        <x-doc.icon name="grid" />
        {{ $title }}
    </div>

    <div class="doc-btns">
        @foreach ($items as $item)
            <div class="doc-btnref" style="--btn-color: {{ $item['color'] ?? 'var(--brand-500)' }}">
                <div class="doc-btnref__icon">
                    <x-doc.icon :name="$item['icon'] ?? 'play'" />
                </div>

                <div>
                    <div class="doc-btnref__name">
                        {{ $item['name'] }}
                        @if (! empty($item['pill']))
                            <span class="doc-btnref__pill">{{ $item['pill'] }}</span>
                        @endif
                    </div>

                    <div class="doc-btnref__rows">
                        @if (! empty($item['does']))
                            <div class="doc-btnref__row">
                                <span class="doc-btnref__key">بيعمل إيه؟</span>
                                <span class="doc-btnref__val">{!! $item['does'] !!}</span>
                            </div>
                        @endif

                        @if (! empty($item['when']))
                            <div class="doc-btnref__row">
                                <span class="doc-btnref__key">بيظهر إمتى؟</span>
                                <span class="doc-btnref__val">{!! $item['when'] !!}</span>
                            </div>
                        @endif

                        @if (! empty($item['after']))
                            <div class="doc-btnref__row">
                                <span class="doc-btnref__key">وبعدين؟</span>
                                <span class="doc-btnref__val">{!! $item['after'] !!}</span>
                            </div>
                        @endif

                        @if (! empty($item['perm']))
                            <div class="doc-btnref__row">
                                <span class="doc-btnref__key">مين يقدر؟</span>
                                <span class="doc-btnref__val">{!! $item['perm'] !!}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
