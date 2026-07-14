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
        /* علامة صح — مربع مرسوم بالـ CSS (رموز ☑/☐ غير متوفرة في خط الـ PDF). */
        .chk { display: inline-block; width: 10px; height: 10px; line-height: 10px; border: 1px solid #374151; text-align: center; font-size: 9px; font-weight: bold; }
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
            <td class="k">{{ __('resources.quality_sheets.pdf.cross_section') }}</td>
            <td>{{ $sheet->cross_section ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.cross_section_e') }}</td>
            <td>{{ $sheet->cross_section_e ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.ampere') }}</td>
            <td>{{ $sheet->ampere ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('resources.quality_sheets.pdf.external_body') }}</td>
            <td>{{ $sheet->external_body ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.protection_degree') }}</td>
            <td>{{ $sheet->protection_degree ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.paint') }}</td>
            <td>{{ $sheet->paint ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('resources.quality_sheets.pdf.model') }}</td>
            <td>{{ $sheet->model ?: '—' }}</td>
            <td class="k">{{ __('resources.quality_sheets.pdf.status') }}</td>
            <td colspan="3">{{ $sheet->status?->getLabel() }}</td>
        </tr>
    </table>

    @php
        // خانات الاختبار — قراءتان لكل خانة تُعرَضان «r1 / r2».
        $tests = ['test_pe_l123n', 'test_fe_l123n', 'test_n_l12l3', 'test_l1_l2l3', 'test_l2_l3'];
        $check = fn ($v) => '<span class="chk">' . ($v ? '√' : '') . '</span>';
        $readings = function ($line, $test) {
            $r1 = $line->{$test . '_r1'};
            $r2 = $line->{$test . '_r2'};
            return trim(($r1 ?? '') . ' / ' . ($r2 ?? ''), ' /') === '' ? '—' : ($r1 ?? '') . ' / ' . ($r2 ?? '');
        };
    @endphp

    <table class="grid">
        <thead>
            <tr>
                <th style="width: 4%">{{ __('resources.quality_sheets.pdf.line_no') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.piece_number') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.visual_quality') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.assembly') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.earth_bond_pe_fe') }}</th>
                <th>{{ __('resources.quality_sheets.pdf.required_size') }}</th>
                @foreach ($tests as $test)
                    <th>{{ __("resources.quality_sheets.pdf.{$test}") }}</th>
                @endforeach
                <th>{{ __('resources.quality_sheets.pdf.notes') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sheet->lines as $i => $line)
                <tr>
                    <td class="center">{{ $line->line_no ?? $i + 1 }}</td>
                    <td>{{ $line->piece_number }}</td>
                    <td class="center">{!! $check($line->visual_quality) !!}</td>
                    <td class="center">{!! $check($line->assembly) !!}</td>
                    <td class="center">{!! $check($line->earth_bond_pe_fe) !!}</td>
                    <td>{{ $line->required_size }}</td>
                    @foreach ($tests as $test)
                        <td class="center">{{ $readings($line, $test) }}</td>
                    @endforeach
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
