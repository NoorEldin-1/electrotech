{{-- قائمة التدفقات النقدية — ماليات.pptx سلايدات 8 و 9 --}}
@php($s = $statement)
@php($signed = fn (float $v): string => $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2))
<x-pdf.statement-layout
    :logo="$logo"
    :doc-title="__('resources.cash_flow_statement.title')"
    :period-line="__('resources.financial_statements.pdf.period', [
        'from' => $from->translatedFormat('d M Y'),
        'to' => $to->translatedFormat('d M Y'),
    ])"
>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('resources.financial_statements.columns.description') }}</th>
                <th style="width: 17%">{{ __('resources.cash_flow_statement.columns.opening') }}</th>
                <th style="width: 17%">{{ __('resources.cash_flow_statement.columns.closing') }}</th>
                <th style="width: 20%">{{ __('resources.cash_flow_statement.columns.effect') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr class="group">
                <td colspan="4">{{ __('resources.cash_flow_statement.sections.operating') }}</td>
            </tr>
            <tr>
                <td>{{ __('resources.cash_flow_statement.rows.net_profit') }}</td>
                <td></td><td></td>
                <td class="num">{{ $signed($s['net_profit']) }}</td>
            </tr>
            @foreach ($s['adjustments'] as $adjustment)
                <tr>
                    <td class="indent">
                        {{ ['( − )', '( · )', '( + )'][$adjustment['sign'] <=> 0] }}
                        {{ __('resources.cash_flow_statement.rows.' . $adjustment['label']) }}
                    </td>
                    <td></td><td></td>
                    <td class="num">{{ $signed($adjustment['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td>{{ __('resources.cash_flow_statement.rows.operating_profit_before_wc') }}</td>
                <td></td><td></td>
                <td class="num">{{ $signed($s['operating_profit_before_wc']) }}</td>
            </tr>

            @foreach ($s['working_capital'] as $row)
                <tr>
                    <td class="indent">{{ __('resources.cash_flow_statement.rows.change_in', ['account' => $row['label']]) }}</td>
                    <td class="num muted">{{ number_format($row['opening'], 2) }}</td>
                    <td class="num muted">{{ number_format($row['closing'], 2) }}</td>
                    <td class="num">{{ $signed($row['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td>{{ __('resources.cash_flow_statement.rows.operating_cash') }}</td>
                <td></td><td></td>
                <td class="num">{{ $signed($s['operating_cash']) }}</td>
            </tr>

            <tr class="group">
                <td colspan="4">{{ __('resources.cash_flow_statement.sections.investing') }}</td>
            </tr>
            @foreach ($s['investing'] as $row)
                <tr>
                    <td class="indent">{{ __('resources.cash_flow_statement.rows.change_in', ['account' => $row['label']]) }}</td>
                    <td class="num muted">{{ number_format($row['opening'], 2) }}</td>
                    <td class="num muted">{{ number_format($row['closing'], 2) }}</td>
                    <td class="num">{{ $signed($row['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td>{{ __('resources.cash_flow_statement.rows.investing_total') }}</td>
                <td></td><td></td>
                <td class="num">{{ $signed($s['investing_total']) }}</td>
            </tr>

            <tr class="group">
                <td colspan="4">{{ __('resources.cash_flow_statement.sections.financing') }}</td>
            </tr>
            @foreach ($s['financing'] as $row)
                <tr>
                    <td class="indent">{{ __('resources.cash_flow_statement.rows.change_in', ['account' => $row['label']]) }}</td>
                    <td class="num muted">{{ number_format($row['opening'], 2) }}</td>
                    <td class="num muted">{{ number_format($row['closing'], 2) }}</td>
                    <td class="num">{{ $signed($row['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td>{{ __('resources.cash_flow_statement.rows.financing_total') }}</td>
                <td></td><td></td>
                <td class="num">{{ $signed($s['financing_total']) }}</td>
            </tr>

            <tr class="subtotal">
                <td>{{ __('resources.cash_flow_statement.rows.net_change') }}</td>
                <td></td><td></td>
                <td class="num">{{ $signed($s['net_change']) }}</td>
            </tr>
            <tr>
                <td>{{ __('resources.cash_flow_statement.rows.opening_cash') }}</td>
                <td></td><td></td>
                <td class="num">{{ number_format($s['opening_cash'], 2) }}</td>
            </tr>
            <tr class="grand">
                <td>{{ __('resources.cash_flow_statement.rows.closing_cash') }}</td>
                <td></td><td></td>
                <td class="num">{{ number_format($s['derived_closing_cash'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- «ويتم مطابقته مع الواقع» --}}
    <table class="grid">
        <tbody>
            <tr>
                <td>{{ __('resources.cash_flow_statement.reconciliation.actual') }}</td>
                <td class="num" style="width: 25%">{{ number_format($s['actual_closing_cash'], 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('resources.cash_flow_statement.reconciliation.difference') }}</td>
                <td class="num {{ $s['reconciled'] ? 'ok' : 'bad' }}">{{ number_format($s['reconciliation_difference'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Which of the two formulas produced these figures. Printed documents go
         out to people outside the company, so this stays product language: no
         configuration keys, no reference to the requirements document. --}}
    @php($variant = $s['add_back_non_cash'] ? 'add_back' : 'client')

    <div class="note">
        <strong>{{ __('resources.cash_flow_statement.formula_note.heading_' . $variant) }}</strong><br>
        {{ __('resources.cash_flow_statement.formula_note.body_' . $variant) }}
    </div>
</x-pdf.statement-layout>
