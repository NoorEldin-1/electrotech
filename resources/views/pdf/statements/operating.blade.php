{{-- قائمة التشغيل — ماليات.pptx سلايد 2 --}}
<x-pdf.statement-layout
    :logo="$logo"
    :doc-title="__('resources.operating_statement.title')"
    :period-line="__('resources.financial_statements.pdf.period', [
        'from' => $from->translatedFormat('d M Y'),
        'to' => $to->translatedFormat('d M Y'),
    ])"
>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('resources.financial_statements.columns.line') }}</th>
                <th style="width: 15%">{{ __('resources.financial_statements.columns.account_code') }}</th>
                <th style="width: 22%">{{ __('resources.financial_statements.columns.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statement['rows'] as $index => $row)
                <tr>
                    <td>{{ $index === 0 ? '' : '+ ' }}{{ $row['account']->name }}</td>
                    <td class="muted">{{ $row['account']->code ?? '—' }}</td>
                    <td class="num">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td colspan="2">{{ __('resources.operating_statement.cost_of_sales') }}</td>
                <td class="num">{{ number_format($statement['total'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">{{ __('resources.operating_statement.chart_note') }}</div>
</x-pdf.statement-layout>
