{{-- قائمة المركز المالى — ماليات.pptx سلايدات 4–6 --}}
@php($s = $statement)
<x-pdf.statement-layout
    :logo="$logo"
    :doc-title="__('resources.balance_sheet.title')"
    :period-line="__('resources.financial_statements.pdf.as_of', ['date' => $s['as_of']->translatedFormat('d M Y')])"
>
    {{-- الأصول طويلة الأجل: التكلفة / مجمع الإهلاك / الصافى --}}
    <div class="section-title">{{ __('resources.balance_sheet.sections.long_term_assets') }}</div>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('resources.financial_statements.columns.description') }}</th>
                <th style="width: 20%">{{ __('resources.balance_sheet.columns.cost') }}</th>
                <th style="width: 20%">{{ __('resources.balance_sheet.columns.accumulated_depreciation') }}</th>
                <th style="width: 20%">{{ __('resources.balance_sheet.columns.net') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($s['fixed_assets']['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ number_format($row['cost'], 2) }}</td>
                    <td class="num">{{ number_format($row['accumulated'], 2) }}</td>
                    <td class="num">{{ number_format($row['net'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td>{{ __('resources.balance_sheet.rows.total_long_term_assets') }}</td>
                <td class="num">{{ number_format($s['fixed_assets']['cost'], 2) }}</td>
                <td class="num">{{ number_format($s['fixed_assets']['accumulated'], 2) }}</td>
                <td class="num">{{ number_format($s['fixed_assets']['net'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    @foreach ([
        ['title' => __('resources.balance_sheet.sections.current_assets'), 'data' => $s['current_assets'], 'total' => __('resources.balance_sheet.rows.total_current_assets')],
        ['title' => __('resources.balance_sheet.sections.current_liabilities'), 'data' => $s['current_liabilities'], 'total' => __('resources.balance_sheet.rows.total_current_liabilities')],
    ] as $block)
        <div class="section-title">{{ $block['title'] }}</div>
        <table class="grid">
            <tbody>
                @foreach ($block['data']['rows'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num" style="width: 25%">{{ number_format($row['amount'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td>{{ $block['total'] }}</td>
                    <td class="num">{{ number_format($block['data']['total'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    {{-- معادلات سلايد 4 --}}
    <table class="grid">
        <tbody>
            <tr class="subtotal">
                <td>{{ __('resources.balance_sheet.rows.working_capital_formula') }}</td>
                <td class="num" style="width: 25%">{{ number_format($s['working_capital'], 2) }}</td>
            </tr>
            <tr class="grand">
                <td>{{ __('resources.balance_sheet.rows.total_investment_formula') }}</td>
                <td class="num">{{ number_format($s['total_investment'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- «يتم تمويلة على النحو التالى» --}}
    <div class="section-title">{{ __('resources.balance_sheet.sections.funding') }}</div>
    <table class="grid">
        <tbody>
            @foreach ($s['funding']['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num" style="width: 25%">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td>{{ __('resources.balance_sheet.rows.total_funding') }}</td>
                <td class="num">{{ number_format($s['funding']['total'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note {{ $s['balanced'] ? 'ok' : 'bad' }}">
        {{ $s['balanced']
            ? __('resources.balance_sheet.balanced')
            : __('resources.balance_sheet.unbalanced', ['difference' => number_format($s['difference'], 2)]) }}
    </div>

    {{-- حاشية سلايد 6 --}}
    @foreach ($s['memo'] as $row)
        <div class="note">
            {{ __('resources.balance_sheet.memo_line', [
                'account' => $row['account']->name,
                'amount' => number_format($row['amount'], 2),
            ]) }}
        </div>
    @endforeach
</x-pdf.statement-layout>
