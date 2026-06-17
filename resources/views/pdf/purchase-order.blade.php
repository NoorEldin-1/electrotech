@php
    /** @var \App\Models\PurchaseOrder $po */
    $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
    $currency = __('resources.common.currency');
    $money = fn ($n) => number_format((float) $n, 2);
    $vatRate = (float) config('procurement.vat_percentage', 14);
    $profitRate = (float) config('procurement.profit_tax_percentage', 1);
    $applyProfitTax = (bool) $po->apply_profit_tax;
@endphp
<!DOCTYPE html>
<html dir="{{ $dir }}" lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: dejavusans, sans-serif; }
        body { font-size: 11px; color: #1f2937; }
        .header { width: 100%; border-bottom: 2px solid #D9723B; padding-bottom: 6px; margin-bottom: 10px; }
        .header td { vertical-align: middle; }
        .company { font-size: 16px; font-weight: bold; color: #D9723B; }
        .doc-title { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 1px; margin: 6px 0 10px; }
        table.meta { width: 100%; margin-bottom: 8px; font-size: 11px; }
        table.meta td { padding: 2px 4px; }
        .meta .k { font-weight: bold; width: 120px; }
        table.boq { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.boq th { background: #374151; color: #fff; font-size: 10px; padding: 5px 4px; border: 1px solid #374151; }
        table.boq td { border: 1px solid #9ca3af; padding: 4px 6px; font-size: 10px; }
        .num { text-align: {{ $dir === 'rtl' ? 'left' : 'right' }}; }
        .center { text-align: center; }
        table.totals { width: 50%; margin-top: 6px; border-collapse: collapse; {{ $dir === 'rtl' ? 'float:left' : 'float:right' }}; }
        table.totals td { border: 1px solid #9ca3af; padding: 4px 8px; font-size: 11px; }
        table.totals .label { font-weight: bold; background: #f3f4f6; }
        .grand td { background: #D9723B; color: #fff; font-weight: bold; }
        .note { clear: both; margin-top: 14px; font-size: 10px; }
        .signature { margin-top: 30px; }
        .footer { border-top: 1px solid #9ca3af; margin-top: 24px; padding-top: 6px; font-size: 9px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 70%">
                @if (! empty($logo))
                    <img src="{{ $logo }}" style="height: 46px">
                @endif
                <div class="company">{{ __('resources.purchase_orders.pdf.company_name') }}</div>
            </td>
            <td style="width: 30%; text-align: {{ $dir === 'rtl' ? 'left' : 'right' }}">
                <div class="doc-title">{{ __('resources.purchase_orders.pdf.title') }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="k">{{ __('resources.purchase_orders.pdf.po_number') }}</td>
            <td>{{ $po->po_number }}</td>
            <td class="k">{{ __('resources.purchase_orders.pdf.date') }}</td>
            <td>{{ $po->created_at?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('resources.purchase_orders.pdf.supplier') }}</td>
            <td>{{ $po->supplier?->name ?: $po->supplier_name }}</td>
            <td class="k">{{ __('resources.purchase_orders.pdf.tax_number') }}</td>
            <td>{{ $po->supplier?->tax_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('resources.purchase_orders.pdf.project') }}</td>
            <td>{{ $po->project?->name }}</td>
            <td class="k">{{ __('resources.purchase_orders.pdf.status') }}</td>
            <td>{{ $po->status?->getLabel() }}</td>
        </tr>
    </table>

    <table class="boq">
        <thead>
            <tr>
                <th style="width: 6%">{{ __('resources.purchase_orders.pdf.item_no') }}</th>
                <th>{{ __('resources.purchase_orders.pdf.item') }}</th>
                <th style="width: 14%">{{ __('resources.purchase_orders.pdf.qty') }}</th>
                <th style="width: 18%">{{ __('resources.purchase_orders.pdf.unit_price') }} ({{ $currency }})</th>
                <th style="width: 18%">{{ __('resources.purchase_orders.pdf.total_price') }} ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($po->items as $i => $line)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $line->item?->name }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 4), '0'), '.') }}</td>
                    <td class="num">{{ $money($line->unit_price) }}</td>
                    <td class="num">{{ $money($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">{{ __('resources.purchase_orders.pdf.subtotal') }}</td>
            <td class="num">{{ $money($po->subtotal) }} {{ $currency }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('resources.purchase_orders.pdf.vat', ['rate' => rtrim(rtrim(number_format($vatRate, 2), '0'), '.')]) }}</td>
            <td class="num">{{ $money($po->vat_amount) }} {{ $currency }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('resources.purchase_orders.pdf.profit_tax', ['rate' => rtrim(rtrim(number_format($profitRate, 2), '0'), '.')]) }}</td>
            <td class="num">− {{ $money($po->profit_tax_amount) }} {{ $currency }}</td>
        </tr>
        <tr class="grand">
            <td>{{ __('resources.purchase_orders.pdf.total') }}</td>
            <td class="num">{{ $money($po->total_amount) }} {{ $currency }}</td>
        </tr>
    </table>
    <div style="clear: both"></div>

    {{-- Slide 8: state explicitly when the supplier is NOT subject to the 1% deduction. --}}
    @unless ($applyProfitTax)
        <div class="note"><strong>{{ __('resources.purchase_orders.pdf.no_profit_tax_note') }}</strong></div>
    @endunless

    <div class="signature">
        <p><strong>{{ __('resources.purchase_orders.pdf.approved_by') }}:</strong>
            {{ $po->approvedBy?->name ?: '...........................' }}</p>
        @if ($po->approved_at)
            <p>{{ __('resources.purchase_orders.pdf.date') }}: {{ $po->approved_at->format('d/m/Y') }}</p>
        @endif
    </div>

    <div class="footer">
        719 El Horreya Road – Loran – Alexandria &nbsp;|&nbsp; Tel: 03 5702275 / 03 5702283 &nbsp;|&nbsp;
        Mobile: 01005417600 &nbsp;|&nbsp; sales@orwa-tech.com
    </div>
</body>
</html>
