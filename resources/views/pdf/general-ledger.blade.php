@php
    /** @var \App\Models\Account $account */
    $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
    $end = $dir === 'rtl' ? 'left' : 'right';
@endphp
<!DOCTYPE html>
<html dir="{{ $dir }}" lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: dejavusans, sans-serif; }
        body { font-size: 9px; color: #1f2937; }
        .header { width: 100%; border-bottom: 2px solid #D9723B; padding-bottom: 6px; margin-bottom: 8px; }
        .company { font-size: 14px; font-weight: bold; color: #D9723B; }
        .doc-title { text-align: center; font-size: 13px; font-weight: bold; background: #dbe5e2; padding: 5px; border: 1px solid #9ca3af; }
        .period { text-align: center; font-size: 10px; color: #6b7280; margin: 4px 0 8px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #dff0d8; color: #1f2937; font-size: 9px; padding: 4px 3px; border: 1px solid #9ca3af; }
        table.grid td { border: 1px solid #9ca3af; padding: 4px 3px; font-size: 9px; }
        .num { text-align: {{ $end }}; }
        .opening td, .totals td { font-weight: bold; background: #f3f4f6; }
        .doc-payment_order { color: #111827; font-weight: bold; }
        .doc-supply_receipt { color: #dc2626; font-weight: bold; }
        .doc-settlement { color: #16a34a; font-weight: bold; }
        .credit { color: #dc2626; }
        .balance { color: #1d4ed8; font-weight: bold; }
        .footer { border-top: 1px solid #9ca3af; margin-top: 16px; padding-top: 5px; font-size: 8px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 70%">
                @if (! empty($logo))
                    <img src="{{ $logo }}" style="height: 38px">
                @endif
                <div class="company">{{ __('resources.quality_sheets.pdf.company_name') }}</div>
            </td>
            <td style="width: 30%; text-align: {{ $end }}">{{ now()->format('Y-m-d H:i') }}</td>
        </tr>
    </table>

    <div class="doc-title">{{ __('resources.general_ledger_report.heading', ['account' => $account->name]) }}</div>
    <div class="period">
        {{ __('resources.general_ledger_report.period', [
            'from' => $from?->translatedFormat('d M Y') ?? '—',
            'to' => $to?->translatedFormat('d M Y') ?? '—',
        ]) }}
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('resources.general_ledger_report.columns.date') }}</th>
                <th>{{ __('resources.general_ledger_report.columns.entry_serial') }}</th>
                <th>{{ __('resources.general_ledger_report.columns.document_number') }}</th>
                <th>{{ __('resources.general_ledger_report.columns.description') }}</th>
                <th>{{ __('resources.general_ledger_report.columns.debit') }}</th>
                <th>{{ __('resources.general_ledger_report.columns.credit') }}</th>
                <th>{{ __('resources.general_ledger_report.columns.balance') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr class="opening">
                <td colspan="4">{{ __('resources.general_ledger_report.opening_balance') }}</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num balance">{{ number_format($opening, 2) }}</td>
            </tr>

            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['date']->format('d-M-y') }}</td>
                    <td class="num">{{ $row['entry_serial'] }}</td>
                    <td class="num doc-{{ $row['document_type']->value }}">{{ $row['document_number'] ?? '—' }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td class="num">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '' }}</td>
                    <td class="num credit">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '' }}</td>
                    <td class="num balance">{{ number_format($row['balance'], 2) }}</td>
                </tr>
            @endforeach

            @if ($rows->isEmpty())
                <tr><td colspan="7" style="text-align: center">{{ __('resources.general_ledger_report.empty') }}</td></tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="totals">
                <td colspan="4">{{ __('resources.general_ledger_report.totals') }}</td>
                <td class="num">{{ number_format($totals['debit'], 2) }}</td>
                <td class="num">{{ number_format($totals['credit'], 2) }}</td>
                <td class="num balance">{{ number_format($closing, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">{{ __('resources.quality_sheets.pdf.company_name') }} — {{ $account->currency }}</div>
</body>
</html>
