@php
    /** @var \App\Models\WorkOrder $workOrder */
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
        .doc-title { text-align: center; font-size: 13px; font-weight: bold; margin: 6px 0 8px; }
        table.meta { width: 100%; margin-bottom: 8px; font-size: 9px; }
        table.meta td { padding: 2px 4px; }
        .k { font-weight: bold; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #374151; color: #fff; font-size: 8px; padding: 4px 3px; border: 1px solid #374151; }
        table.grid td { border: 1px solid #9ca3af; padding: 4px 3px; font-size: 8px; }
        .num { text-align: {{ $end }}; }
        .over { color: #dc2626; font-weight: bold; }
        .under { color: #16a34a; font-weight: bold; }
        .totals td { font-weight: bold; background: #f3f4f6; }
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

    <div class="doc-title">{{ __('resources.material_variance.heading', ['order' => $workOrder->wo_number]) }}</div>

    <table class="meta">
        <tr>
            <td class="k">{{ __('resources.work_orders.fields.project') }}</td>
            <td>{{ $workOrder->project?->name ?? '—' }}</td>
            <td class="k">{{ __('resources.work_orders.fields.output_item') }}</td>
            <td>{{ $workOrder->outputItem?->name ?? '—' }}</td>
            <td class="k">{{ __('resources.work_orders.fields.planned_quantity') }}</td>
            <td>{{ number_format((float) $workOrder->planned_quantity, 2) }}</td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('resources.material_variance.columns.item') }}</th>
                <th>{{ __('resources.material_variance.columns.planned') }}</th>
                <th>{{ __('resources.material_variance.columns.issued') }}</th>
                <th>{{ __('resources.material_variance.columns.returned') }}</th>
                <th>{{ __('resources.material_variance.columns.net_issued') }}</th>
                <th>{{ __('resources.material_variance.columns.variance') }}</th>
                <th>{{ __('resources.material_variance.columns.variance_percentage') }}</th>
                <th>{{ __('resources.material_variance.columns.variance_value') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($variance['rows'] as $row)
                @php($class = $row['variance'] > 0 ? 'over' : 'under')
                <tr>
                    <td>{{ $row['item']?->name ?? '—' }}</td>
                    <td class="num">{{ number_format($row['planned'], 2) }}</td>
                    <td class="num">{{ number_format($row['issued'], 2) }}</td>
                    <td class="num">{{ number_format($row['returned'], 2) }}</td>
                    <td class="num">{{ number_format($row['net_issued'], 2) }}</td>
                    <td class="num {{ $class }}">{{ number_format($row['variance'], 2) }}</td>
                    <td class="num {{ $class }}">{{ $row['variance_percentage'] === null ? '—' : number_format($row['variance_percentage'], 2) . '%' }}</td>
                    <td class="num {{ $class }}">{{ number_format($row['variance_value'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align: center">{{ __('resources.material_variance.empty') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="totals">
                <td colspan="5">{{ __('resources.material_variance.planned_value') }}: {{ number_format($variance['planned_value'], 2) }}
                    — {{ __('resources.material_variance.issued_value') }}: {{ number_format($variance['issued_value'], 2) }}</td>
                <td colspan="3" class="num {{ $variance['variance_value'] > 0 ? 'over' : 'under' }}">
                    {{ __('resources.material_variance.loss_value') }}: {{ number_format($variance['variance_value'], 2) }}
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">{{ __('resources.quality_sheets.pdf.company_name') }}</div>
</body>
</html>
