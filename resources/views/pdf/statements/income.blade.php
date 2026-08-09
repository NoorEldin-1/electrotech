{{-- قائمة الدخل — ماليات.pptx سلايد 3 (بعمودَي جزئى وكلى) --}}
@php($s = $statement)
<x-pdf.statement-layout
    :logo="$logo"
    :doc-title="__('resources.income_statement.title')"
    :period-line="__('resources.financial_statements.pdf.period', [
        'from' => $from->translatedFormat('d M Y'),
        'to' => $to->translatedFormat('d M Y'),
    ])"
>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('resources.financial_statements.columns.description') }}</th>
                <th style="width: 20%">{{ __('resources.financial_statements.columns.partial') }}</th>
                <th style="width: 20%">{{ __('resources.financial_statements.columns.total') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ __('resources.income_statement.rows.net_sales') }}
                    <span class="muted">({{ number_format($s['net_sales']['sales'], 2) }} − {{ number_format($s['net_sales']['returns'], 2) }})</span>
                </td>
                <td class="num">{{ number_format($s['net_sales']['net'], 2) }}</td>
                <td></td>
            </tr>
            <tr>
                <td>( − ) {{ __('resources.income_statement.rows.cost_of_sales') }}</td>
                <td class="num">{{ number_format($s['cost_of_sales'], 2) }}</td>
                <td></td>
            </tr>
            <tr class="subtotal">
                <td>{{ __('resources.income_statement.rows.gross_profit') }}</td>
                <td></td>
                <td class="num">{{ number_format($s['gross_profit'], 2) }}</td>
            </tr>

            @foreach ($s['revenues']['rows'] as $row)
                <tr>
                    <td>( + ) {{ __('resources.income_statement.rows.' . $row['label']) }}</td>
                    <td class="num">{{ number_format($row['amount'], 2) }}</td>
                    <td></td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td>{{ __('resources.income_statement.rows.total_revenues') }}</td>
                <td></td>
                <td class="num">{{ number_format($s['revenues']['total'], 2) }}</td>
            </tr>

            @foreach ($s['expenses']['rows'] as $row)
                <tr>
                    <td>( − ) {{ __('resources.income_statement.rows.' . $row['label']) }}</td>
                    <td class="num">{{ number_format($row['amount'], 2) }}</td>
                    <td></td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td>{{ __('resources.income_statement.rows.total_expenses') }}</td>
                <td></td>
                <td class="num">{{ number_format($s['expenses']['total'], 2) }}</td>
            </tr>

            <tr class="grand">
                <td>{{ __('resources.income_statement.rows.net_profit') }}</td>
                <td></td>
                <td class="num">{{ number_format($s['net_profit'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">{{ __('resources.income_statement.formula_note') }}</div>
</x-pdf.statement-layout>
