<x-filament-panels::page class="et-report">
    {{-- Search --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-sm">
            <label for="operations-search" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.operations_overview.filters.search') }}
            </label>
            <input
                id="operations-search"
                type="text"
                wire:model.live.debounce.400ms="search"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]"
            />
        </div>
    </div>

    @php($operations = $this->getOperations())

    @if ($operations->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operations_overview.empty') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.operations_overview.columns.row') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.operations_overview.columns.operation') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.operations_overview.columns.client') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.operations_overview.columns.estimated_budget') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.operations_overview.columns.actual_cost') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('resources.operations_overview.columns.usage') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('resources.operations_overview.columns.boms') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('resources.operations_overview.columns.work_orders') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('resources.operations_overview.columns.purchase_orders') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('resources.operations_overview.columns.deliveries') }}</th>
                            <th class="px-3 py-2 text-center font-medium">{{ __('resources.operations_overview.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                        @foreach ($operations as $index => $op)
                            @php($budget = (float) $op->estimated_budget)
                            @php($actual = (float) $op->actual_cost)
                            @php($usage = $budget > 0 ? ($actual / $budget) * 100 : null)
                            @php($usageColor = $usage === null ? 'gray' : ($usage > 100 ? 'danger' : ($usage >= 80 ? 'warning' : 'success')))
                            <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                <td class="px-3 py-2 text-start tabular-nums text-gray-500 dark:text-[var(--dark-text-muted)]">{{ $index + 1 }}</td>
                                <td class="px-3 py-2 text-start">
                                    @if ($op->code)
                                        <span class="text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $op->code }}</span>
                                    @endif
                                    {{ $op->name }}
                                </td>
                                <td class="px-3 py-2 text-start">{{ $op->customer?->name ?? $op->client_name ?? __('resources.common.no_data') }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($budget, 2) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($actual, 2) }}</td>
                                <td class="px-3 py-2 text-center">
                                    @if ($usage === null)
                                        <span class="text-gray-400 dark:text-[var(--dark-text-faint)]">{{ __('resources.common.no_data') }}</span>
                                    @else
                                        <x-filament::badge :color="$usageColor">{{ number_format($usage, 0) }}%</x-filament::badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $op->boms_count }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $op->work_orders_count }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $op->purchase_orders_count }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $op->delivery_vouchers_count }}</td>
                                <td class="px-3 py-2 text-center">
                                    <x-filament::badge :color="$op->status->getColor()">{{ $op->status->getLabel() }}</x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
