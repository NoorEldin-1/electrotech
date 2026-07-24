@php
    /** @var array $daybook */
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
        .doc-title { text-align: center; font-size: 13px; font-weight: bold; margin: 4px 0; }
        .period { text-align: center; font-size: 10px; color: #6b7280; margin-bottom: 8px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #374151; color: #fff; font-size: 8px; padding: 3px 2px; border: 1px solid #374151; }
        table.grid td { border: 1px solid #9ca3af; padding: 3px 3px; font-size: 8px; }
        .num { text-align: {{ $end }}; }
        .totals td { font-weight: bold; background: #f3f4f6; }
        /* Document-number colours, as read in the paper daybook. */
        .doc-payment_order { color: #111827; font-weight: bold; }
        .doc-supply_receipt { color: #dc2626; font-weight: bold; }
        .doc-settlement { color: #16a34a; font-weight: bold; }
        .credit { color: #dc2626; }
        .footer { border-top: 1px solid #9ca3af; margin-top: 14px; padding-top: 5px; font-size: 8px; color: #6b7280; text-align: center; }
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
            <td style="width: 30%; text-align: {{ $end }}">
                {{ now()->format('Y-m-d H:i') }}
            </td>
        </tr>
    </table>

    <div class="doc-title">{{ __('resources.journal_daybook.title') }} @if ($currency) — {{ $currency }} @endif</div>
    <div class="period">
        {{ __('resources.journal_daybook.period', [
            'from' => $from?->translatedFormat('d M Y') ?? '—',
            'to' => $to?->translatedFormat('d M Y') ?? '—',
        ]) }}
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th rowspan="2">{{ __('resources.journal_daybook.columns.date') }}</th>
                <th rowspan="2">{{ __('resources.journal_daybook.columns.entry_serial') }}</th>
                <th rowspan="2">{{ __('resources.journal_daybook.columns.document_number') }}</th>
                <th rowspan="2">{{ __('resources.journal_daybook.columns.description') }}</th>
                @foreach ($daybook['accounts'] as $account)
                    <th colspan="2">{{ $account->name }}</th>
                @endforeach
                <th colspan="2">{{ __('resources.journal_daybook.columns.entry_totals') }}</th>
            </tr>
            <tr>
                @foreach ($daybook['accounts'] as $account)
                    <th>{{ __('resources.journal_daybook.columns.debit') }}</th>
                    <th>{{ __('resources.journal_daybook.columns.credit') }}</th>
                @endforeach
                <th>{{ __('resources.journal_daybook.columns.debit') }}</th>
                <th>{{ __('resources.journal_daybook.columns.credit') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($daybook['rows'] as $row)
                @php($entry = $row['entry'])
                <tr>
                    <td>{{ $entry->entry_date?->format('d-M-y') }}</td>
                    <td class="num">{{ $entry->entry_serial }}</td>
                    <td class="num doc-{{ $entry->document_type->value }}">{{ $entry->document_number ?? '—' }}</td>
                    <td>{{ $entry->description }}</td>
                    @foreach ($daybook['accounts'] as $account)
                        @php($cell = $row['cells'][$account->id] ?? ['debit' => 0.0, 'credit' => 0.0])
                        <td class="num">{{ $cell['debit'] > 0 ? number_format($cell['debit'], 2) : '' }}</td>
                        <td class="num credit">{{ $cell['credit'] > 0 ? number_format($cell['credit'], 2) : '' }}</td>
                    @endforeach
                    <td class="num">{{ number_format($row['total_debit'], 2) }}</td>
                    <td class="num">{{ number_format($row['total_credit'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 6 + (count($daybook['accounts']) * 2) }}" style="text-align: center">
                        {{ __('resources.journal_daybook.empty') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="totals">
                <td colspan="4">{{ __('resources.journal_daybook.totals') }}</td>
                @foreach ($daybook['accounts'] as $account)
                    @php($totals = $daybook['column_totals'][$account->id] ?? ['debit' => 0.0, 'credit' => 0.0])
                    <td class="num">{{ number_format($totals['debit'], 2) }}</td>
                    <td class="num">{{ number_format($totals['credit'], 2) }}</td>
                @endforeach
                <td class="num">{{ number_format($daybook['total_debit'], 2) }}</td>
                <td class="num">{{ number_format($daybook['total_credit'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">{{ __('resources.quality_sheets.pdf.company_name') }}</div>
</body>
</html>
