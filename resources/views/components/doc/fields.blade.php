{{--
    Field / column reference table.

    $rows: [ ['name' => 'كود المشروع', 'req' => true, 'desc' => '...', 'note' => '...'], ... ]
--}}
@props([
    'caption' => 'البيانات الموجودة في الشاشة',
    'headers' => ['الحقل', 'يعني إيه بالظبط؟', 'ملاحظات'],
    'rows' => [],
])

<div class="doc-tablewrap doc-scroll">
    <table class="doc-table">
        <caption>{{ $caption }}</caption>

        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th scope="col">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="doc-t-name">
                        {{ $row['name'] }}
                        @if (! empty($row['req']))
                            <span class="doc-t-req" title="حقل إجباري">*</span>
                        @endif
                    </td>
                    <td>{!! $row['desc'] !!}</td>
                    <td>{!! $row['note'] ?? '—' !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
