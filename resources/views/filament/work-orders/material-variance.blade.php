{{--
    مقارنة الخامات: أمر التشغيل مقابل أوامر الصرف (قائمة المواد سلايد 1).
    الفرق الموجب = هالك/زيادة صرف، والسالب = توفير.
--}}
<div class="et-report space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-[var(--dark-text-muted)]">
            <span>{{ __('resources.material_variance.planned_value') }}:
                <strong class="text-gray-950 dark:text-[var(--dark-text)]">{{ number_format($variance['planned_value'], 2) }}</strong>
            </span>
            <span>{{ __('resources.material_variance.issued_value') }}:
                <strong class="text-gray-950 dark:text-[var(--dark-text)]">{{ number_format($variance['issued_value'], 2) }}</strong>
            </span>
            <span>{{ __('resources.material_variance.loss_value') }}:
                <strong class="{{ $variance['variance_value'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                    {{ number_format($variance['variance_value'], 2) }}
                </strong>
            </span>
        </div>

        <x-filament::button tag="a" target="_blank" color="gray" icon="heroicon-o-printer"
            href="{{ route('work_orders.material_variance.pdf', ['workOrder' => $workOrder->getKey()]) }}">
            {{ __('resources.common.print') }}
        </x-filament::button>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                    <th class="px-3 py-2 text-start font-medium">{{ __('resources.material_variance.columns.item') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.material_variance.columns.planned') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.material_variance.columns.issued') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.material_variance.columns.returned') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.material_variance.columns.net_issued') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.material_variance.columns.variance') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.material_variance.columns.variance_percentage') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.material_variance.columns.variance_value') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                @forelse ($variance['rows'] as $row)
                    @php($over = $row['variance'] > 0)
                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ $row['item']?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['planned'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['issued'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['returned'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['net_issued'], 2) }}</td>
                        <td class="px-3 py-2 text-end font-bold tabular-nums {{ $over ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                            {{ number_format($row['variance'], 2) }}
                        </td>
                        <td class="px-3 py-2 text-end tabular-nums {{ $over ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                            {{ $row['variance_percentage'] === null ? '—' : number_format($row['variance_percentage'], 2) . '%' }}
                        </td>
                        <td class="px-3 py-2 text-end tabular-nums {{ $over ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                            {{ number_format($row['variance_value'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">
                            {{ __('resources.material_variance.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
