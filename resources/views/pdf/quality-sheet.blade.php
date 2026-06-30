@php
    /** @var \App\Models\QualitySheet $sheet */
    $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html dir="{{ $dir }}" lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: dejavusans, sans-serif; }
        body { font-size: 10px; color: #1f2937; }
        .header { width: 100%; border-bottom: 2px solid #D9723B; padding-bottom: 6px; margin-bottom: 10px; }
        .header td { vertical-align: middle; }
        .company { font-size: 16px; font-weight: bold; color: #D9723B; }
        .doc-title { text-align: center; font-size: 14px; font-weight: bold; letter-spacing: 1px; margin: 6px 0 10px; }
        table.meta { width: 100%; margin-bottom: 8px; font-size: 10px; }
        table.meta td { padding: 2px 4px; }
        .meta .k { font-weight: bold; width: 110px; }
        table.grid { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.grid th { background: #374151; color: #fff; font-size: 9px; padding: 4px 3px; border: 1px solid #374151; }
        table.grid td { border: 1px solid #9ca3af; padding: 4px 4px; font-size: 9px; }
        .center { text-align: center; }
        .signatures { width: 100%; margin-top: 28px; }
        .signatures td { width: 50%; vertical-align: top; padding: 6px 10px; }
        .sig-line { margin-top: 26px; border-top: 1px solid #6b7280; padding-top: 4px; }
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
                <div class="company">{{ __('resources.quality_sheets.pdf.company_name') }}</div>
            </td>
            <td style="width: 30%; text-align: {{ $dir === 'rtl' ? 'left' : 'right' }}">
                <div class="doc-title">{{ __('resources.quality_sheets.pdf.title') }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="k">{{ __('resources.quality_sheets.pdf.sheet_number') }}</td>
            <td>{{ $sheet->sheet_number }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.test_date') }}</td>
            <td>{{ $sheet->test_date?->format('d/m/Y') ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.work_order') }}</td>
            <td>{{ $sheet->workOrder?->wo_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('resources.quality_sheets.pdf.operation_name') }}</td>
            <td>{{ $sheet->operation_name ?: ($sheet->workOrder?->project?->name ?: '—') }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.conductor_type') }}</td>
            <td>{{ $sheet->conductor_type ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.poles_count') }}</td>
            <td>{{ $sheet->poles_count ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('resources.quality_sheets.pdf.connection_type') }}</td>
            <td>{{ $sheet->connection_type ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.cross_section') }}</td>
            <td>{{ $sheet->cross_section ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.status') }}</td>
            <td>{{ $sheet->status?->getLabel() }}</td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th style="width: 5%">{{ __('resources.quality_sheets.pdf.line_no') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.piece_number') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.assembly') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.visual_quality') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.required_size') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.test_pe_l123n') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.test_fe_l123n') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.test_n_l12l3') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.test_l1_l2l3') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.test_l2_l3') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.notes') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sheet->lines as $i => $line)
                <tr>
                    <td class="center">{{ $line->line_no ?? $i + 1 }}</td>
                    <td>{{ $line->piece_number }}</td>
                    <td>{{ $line->assembly }}</td>
                    <td>{{ $line->visual_quality }}</td>
                    <td>{{ $line->required_size }}</td>
                    <td class="center">{{ $line->test_pe_l123n }}</td>
                    <td class="center">{{ $line->test_fe_l123n }}</td>
                    <td class="center">{{ $line->test_n_l12l3 }}</td>
                    <td class="center">{{ $line->test_l1_l2l3 }}</td>
                    <td class="center">{{ $line->test_l2_l3 }}</td>
                    <td>{{ $line->notes }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($sheet->qa_inspector_notes)
        <p style="margin-top: 10px"><strong>{{ __('resources.quality_sheets.pdf.inspector_notes') }}:</strong>
            {{ $sheet->qa_inspector_notes }}</p>
    @endif

    <table class="signatures">
        <tr>
            <td>
                <strong>{{ __('resources.quality_sheets.pdf.qa_inspector') }}</strong>
                <div class="sig-line">
                    {{ $sheet->qaFilledBy?->name ?: '...........................' }}
                    @if ($sheet->qa_filled_at)
                        <br>{{ $sheet->qa_filled_at->format('d/m/Y') }}
                    @endif
                </div>
            </td>
            <td>
                <strong>{{ __('resources.quality_sheets.pdf.factory_manager') }}</strong>
                <div class="sig-line">
                    {{ $sheet->factoryApprovedBy?->name ?: '...........................' }}
                    @if ($sheet->factory_approved_at)
                        <br>{{ $sheet->factory_approved_at->format('d/m/Y') }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        PRF-01-01 &nbsp;|&nbsp; Quality &amp; Consultation Institute (QCI) &nbsp;|&nbsp;
        719 El Horreya Road – Loran – Alexandria
    </div>
</body>
</html>
