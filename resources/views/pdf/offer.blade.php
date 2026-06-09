@php
    /** @var \App\Models\ProjectOffer $offer */
    $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
    $project = $offer->project;
    $currency = $offer->currency ?: 'EGP';
    $vat = (float) $offer->vat_percentage;
    $showVat = (bool) $offer->show_vat;
    $money = fn ($n) => number_format((float) $n, 2);
    $conductors = $offer->groups
        ->map(fn ($g) => $g->conductor_type?->getLabel())
        ->filter()
        ->unique()
        ->implode(' / ');
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
        .group-title { background: #f3f4f6; font-weight: bold; padding: 5px 6px; margin-top: 10px; border: 1px solid #9ca3af; border-bottom: none; }
        table.boq { width: 100%; border-collapse: collapse; }
        table.boq th { background: #374151; color: #fff; font-size: 10px; padding: 5px 4px; border: 1px solid #374151; }
        table.boq td { border: 1px solid #9ca3af; padding: 4px 6px; font-size: 10px; }
        .num { text-align: {{ $dir === 'rtl' ? 'left' : 'right' }}; }
        .center { text-align: center; }
        table.totals { width: 45%; margin-top: 4px; border-collapse: collapse; {{ $dir === 'rtl' ? 'float:left' : 'float:right' }}; }
        table.totals td { border: 1px solid #9ca3af; padding: 4px 8px; font-size: 11px; }
        table.totals .label { font-weight: bold; background: #f3f4f6; }
        .grand td { background: #D9723B; color: #fff; font-weight: bold; }
        .terms { clear: both; margin-top: 16px; }
        .terms h4 { margin: 0 0 4px; border-bottom: 1px solid #9ca3af; padding-bottom: 2px; }
        .terms .body { white-space: pre-line; font-size: 10px; }
        .signature { margin-top: 26px; }
        .footer { border-top: 1px solid #9ca3af; margin-top: 18px; padding-top: 6px; font-size: 9px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 70%">
                @if (! empty($logo))
                    <img src="{{ $logo }}" style="height: 46px">
                @endif
                <div class="company">{{ __('resources.project_offers.pdf.company_name') }}</div>
            </td>
            <td style="width: 30%; text-align: {{ $dir === 'rtl' ? 'left' : 'right' }}">
                <div class="doc-title">{{ __('resources.project_offers.pdf.quotation') }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="k">{{ __('resources.project_offers.pdf.quotation_no') }}</td>
            <td>{{ $offer->quotation_number ?: ('Q-' . $offer->id . '/' . $offer->submitted_at?->format('Y')) }}</td>
            <td class="k">{{ __('resources.project_offers.pdf.date') }}</td>
            <td>{{ $offer->submitted_at?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('resources.project_offers.pdf.project') }}</td>
            <td>{{ $project->name }}</td>
            <td class="k">{{ __('resources.project_offers.pdf.to') }}</td>
            <td>{{ $project->engineer_name ?: $project->client_name }}</td>
        </tr>
        @if ($conductors !== '')
            <tr>
                <td class="k">{{ __('resources.project_offers.pdf.conductor_type') }}</td>
                <td colspan="3">{{ $conductors }}</td>
            </tr>
        @endif
    </table>

    @foreach ($offer->groups as $group)
        @php
            $groupSubtotal = (float) $group->items->sum('line_total');
            $groupTax = $showVat ? round($groupSubtotal * $vat / 100, 2) : 0;
            $groupGrand = $groupSubtotal + $groupTax;
        @endphp
        <div class="group-title">{{ $group->label }}</div>
        <table class="boq">
            <thead>
                <tr>
                    <th style="width: 6%">{{ __('resources.project_offers.pdf.item_no') }}</th>
                    <th>{{ __('resources.project_offers.pdf.description') }}</th>
                    <th style="width: 10%">{{ __('resources.project_offers.pdf.unit') }}</th>
                    <th style="width: 10%">{{ __('resources.project_offers.pdf.qty') }}</th>
                    <th style="width: 16%">{{ __('resources.project_offers.pdf.unit_price') }} ({{ $currency }})</th>
                    <th style="width: 16%">{{ __('resources.project_offers.pdf.total_price') }} ({{ $currency }})</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group->items as $i => $item)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td>{{ $item->description }}</td>
                        <td class="center">{{ $item->unit }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td>
                        <td class="num">{{ $money($item->unit_price) }}</td>
                        <td class="num">{{ $money($item->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <table class="totals">
            <tr>
                <td class="label">{{ __('resources.project_offers.pdf.subtotal') }}</td>
                <td class="num">{{ $money($groupSubtotal) }} {{ $currency }}</td>
            </tr>
            @if ($showVat)
                <tr>
                    <td class="label">{{ __('resources.project_offers.pdf.taxes') }} ({{ rtrim(rtrim(number_format($vat, 2), '0'), '.') }}%)</td>
                    <td class="num">{{ $money($groupTax) }} {{ $currency }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>{{ __('resources.project_offers.pdf.grand_total') }}</td>
                <td class="num">{{ $money($groupGrand) }} {{ $currency }}</td>
            </tr>
        </table>
        <div style="clear: both"></div>
    @endforeach

    @if ($offer->technical_amount !== null)
        <p style="margin-top: 10px"><strong>{{ __('resources.project_offers.pdf.technical_offer') }}:</strong>
            {{ $money($offer->technical_amount) }} {{ $currency }}</p>
    @endif

    @if (! empty($offer->terms))
        <div class="terms">
            <h4>{{ __('resources.project_offers.pdf.terms_title') }}</h4>
            <div class="body">{{ $offer->terms }}</div>
        </div>
    @endif

    <div class="signature">
        <p>{{ __('resources.project_offers.pdf.best_regards') }}</p>
        <p style="margin-top: 22px"><strong>{{ __('resources.project_offers.pdf.sales_manager') }}</strong><br>
            {{ $offer->submittedBy?->name }}</p>
    </div>

    <div class="footer">
        719 El Horreya Road – Loran – Alexandria &nbsp;|&nbsp; Tel: 03 5702275 / 03 5702283 &nbsp;|&nbsp;
        Mobile: 01005417600 &nbsp;|&nbsp; sales@orwa-tech.com
    </div>
</body>
</html>
