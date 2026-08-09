{{--
    Shared A4 shell for the four printed financial statements (ماليات.pptx).
    Mirrors the styling of pdf/general-ledger.blade.php so a printed statement
    sits next to a printed ledger without looking foreign.
--}}
@props(['logo' => null, 'docTitle', 'periodLine'])

@php
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
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.grid th { background: #dff0d8; color: #1f2937; font-size: 9px; padding: 4px 3px; border: 1px solid #9ca3af; }
        table.grid td { border: 1px solid #9ca3af; padding: 4px 3px; font-size: 9px; }
        .num { text-align: {{ $end }}; }
        .subtotal td { font-weight: bold; background: #f3f4f6; }
        .grand td { font-weight: bold; background: #dbe5e2; font-size: 10px; }
        .group td { font-weight: bold; background: #eef2ff; }
        .indent { padding-{{ $dir === 'rtl' ? 'right' : 'left' }}: 14px !important; }
        .muted { color: #6b7280; }
        .section-title { font-size: 11px; font-weight: bold; margin: 10px 0 4px; color: #374151; }
        .ok { color: #16a34a; font-weight: bold; }
        .bad { color: #dc2626; font-weight: bold; }
        .note { font-size: 8px; color: #6b7280; margin-top: 6px; }
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

    <div class="doc-title">{{ $docTitle }}</div>
    <div class="period">{{ $periodLine }}</div>

    {{ $slot }}

    <div class="footer">{{ __('resources.financial_statements.pdf.footer') }}</div>
</body>
</html>
