@php
    /** @var \App\Models\ProjectOffer $offer */
    $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
    $project = $offer->project;
    $currency = $offer->currency ?: 'EGP';
    $vat = (float) $offer->vat_percentage;
    $showVat = (bool) $offer->show_vat;
    $installationPct = (float) $offer->installation_percentage;
    $showInstallation = (bool) $offer->show_installation;
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
        .greeting { margin: 8px 0 4px; font-size: 11px; }
        .header-note { margin: 4px 0 10px; font-size: 10px; line-height: 1.5; }
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
        .terms ol.terms-list { margin: 4px 0; padding-{{ $dir === 'rtl' ? 'right' : 'left' }}: 18px; }
        .terms ol.terms-list li { font-size: 10px; margin-bottom: 3px; line-height: 1.4; }
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
            <td class="k">{{ __('resources.project_offers.pdf.messrs') }}</td>
            <td>{{ $project->client_name ?: $project->customer?->name }}</td>
        </tr>
        @if (! empty($project->engineer_name))
            <tr>
                <td class="k">{{ __('resources.project_offers.pdf.attention') }}</td>
                <td colspan="3">{{ $project->engineer_name }}</td>
            </tr>
        @endif
        @if ($conductors !== '')
            <tr>
                <td class="k">{{ __('resources.project_offers.pdf.conductor_type') }}</td>
                <td colspan="3">{{ $conductors }}</td>
            </tr>
        @endif
    </table>

    {{-- Slide 7: greeting line before any financial offer. --}}
    <p class="greeting">{{ __('resources.project_offers.pdf.greeting') }}</p>

    {{-- Slides 5 & 11: intro + DKC licence statement, printed before the tables. --}}
    @if (! empty($offer->header_note))
        <div class="header-note">{!! nl2br(e($offer->header_note)) !!}</div>
    @endif

    {{-- Slide 4: with several tables, each table shows only its own subtotal and
         a single authoritative offer-level total is printed once at the end — so
         there is exactly one grand total, matching the form and the stored figure. --}}
    @php
        $multiGroup = $offer->groups->count() > 1;
        $offerSubtotal = 0.0;
    @endphp
    @foreach ($offer->groups as $group)
        @php
            $groupSubtotal = (float) $group->items->sum('line_total');
            $offerSubtotal += $groupSubtotal;
            $groupTax = $showVat ? round($groupSubtotal * $vat / 100, 2) : 0;
            $groupInstallation = $showInstallation ? round($groupSubtotal * $installationPct / 100, 2) : 0;
            $groupGrand = $groupSubtotal + $groupTax + $groupInstallation;
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
            @unless ($multiGroup)
                @if ($showVat)
                    <tr>
                        <td class="label">{{ __('resources.project_offers.pdf.taxes') }} ({{ rtrim(rtrim(number_format($vat, 2), '0'), '.') }}%)</td>
                        <td class="num">{{ $money($groupTax) }} {{ $currency }}</td>
                    </tr>
                @endif
                @if ($showInstallation)
                    <tr>
                        <td class="label">{{ __('resources.project_offers.pdf.installation') }} ({{ rtrim(rtrim(number_format($installationPct, 2), '0'), '.') }}%)</td>
                        <td class="num">{{ $money($groupInstallation) }} {{ $currency }}</td>
                    </tr>
                @endif
                <tr class="grand">
                    <td>{{ __('resources.project_offers.pdf.grand_total') }}</td>
                    <td class="num">{{ $money($groupGrand) }} {{ $currency }}</td>
                </tr>
            @endunless
        </table>
        <div style="clear: both"></div>
    @endforeach

    {{-- Slide 4 & 10: one combined offer-level total when the offer carries more
         than one table. Computed offer-level (tax/installation on the summed
         subtotal) so it equals OfferTotalsService's stored grand_total exactly. --}}
    @if ($multiGroup)
        @php
            $offerSubtotal = round($offerSubtotal, 2);
            $offerTax = $showVat ? round($offerSubtotal * $vat / 100, 2) : 0;
            $offerInstallation = $showInstallation ? round($offerSubtotal * $installationPct / 100, 2) : 0;
            $offerGrand = $offerSubtotal + $offerTax + $offerInstallation;
        @endphp
        <div class="group-title">{{ __('resources.project_offers.pdf.offer_total_title') }}</div>
        <table class="totals">
            <tr>
                <td class="label">{{ __('resources.project_offers.pdf.subtotal') }}</td>
                <td class="num">{{ $money($offerSubtotal) }} {{ $currency }}</td>
            </tr>
            @if ($showVat)
                <tr>
                    <td class="label">{{ __('resources.project_offers.pdf.taxes') }} ({{ rtrim(rtrim(number_format($vat, 2), '0'), '.') }}%)</td>
                    <td class="num">{{ $money($offerTax) }} {{ $currency }}</td>
                </tr>
            @endif
            @if ($showInstallation)
                <tr>
                    <td class="label">{{ __('resources.project_offers.pdf.installation') }} ({{ rtrim(rtrim(number_format($installationPct, 2), '0'), '.') }}%)</td>
                    <td class="num">{{ $money($offerInstallation) }} {{ $currency }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>{{ __('resources.project_offers.pdf.grand_total') }}</td>
                <td class="num">{{ $money($offerGrand) }} {{ $currency }}</td>
            </tr>
        </table>
        <div style="clear: both"></div>
    @endif

    {{-- Slides 3 & 8: special terms sit directly behind the tables… --}}
    @if (! empty($offer->terms))
        <div class="terms">
            <h4>{{ __('resources.project_offers.pdf.special_terms_title') }}</h4>
            {{-- Slide 3: preserve the line-by-line layout the author typed; mpdf
                 collapses pre-line, so emit explicit <br> (escaped first). --}}
            <div class="body">{!! nl2br(e($offer->terms)) !!}</div>
        </div>
    @endif

    {{-- …then the standard (general) terms as a numbered list — Slides 9 & 10. --}}
    @php
        $generalTermLines = collect(preg_split('/\r\n|\r|\n/', (string) $offer->general_terms))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values();
    @endphp
    @if ($generalTermLines->isNotEmpty())
        <div class="terms">
            <h4>{{ __('resources.project_offers.pdf.terms_title') }}</h4>
            <ol class="terms-list">
                @foreach ($generalTermLines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ol>
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
