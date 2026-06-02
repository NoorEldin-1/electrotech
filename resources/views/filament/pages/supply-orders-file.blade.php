<x-filament-panels::page>
    {{-- Operation selector --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-md">
            <label for="supply-file-operation" class="block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                {{ __('resources.supply_orders_file.select_operation') }}
            </label>
            <select
                id="supply-file-operation"
                wire:model.live="projectId"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-900 dark:text-white dark:[color-scheme:dark]"
            >
                <option value="" class="bg-white text-gray-950 dark:bg-gray-900 dark:text-white">—</option>
                @foreach ($this->getProjectOptions() as $id => $label)
                    <option value="{{ $id }}" class="bg-white text-gray-950 dark:bg-gray-900 dark:text-white">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @php($summary = $this->getSummary())

    @if ($summary === null)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.supply_orders_file.empty') }}</p>
        </x-filament::section>
    @else
        {{-- Money summary --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @foreach ([
                'revenue' => 'success',
                'received' => 'success',
                'paid' => 'danger',
                'outstanding' => 'warning',
            ] as $key => $color)
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.supply_orders_file.summary.' . $key) }}</div>
                    <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ number_format((float) $summary[$key], 2) }}
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        {{-- Purchase / supply orders --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('resources.supply_orders_file.orders_heading') }}</x-slot>

            @php($orders = $this->getPurchaseOrders())

            @if ($orders->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.supply_orders_file.no_orders') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-start font-medium">{{ __('resources.supply_orders_file.columns.po_number') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ __('resources.supply_orders_file.columns.supplier') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ __('resources.supply_orders_file.columns.status') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('resources.supply_orders_file.columns.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($orders as $po)
                                <tr class="text-gray-950 dark:text-white">
                                    <td class="px-3 py-2 text-start">{{ $po->po_number }}</td>
                                    <td class="px-3 py-2 text-start">{{ $po->supplier?->name ?? $po->supplier_name ?? __('resources.common.no_data') }}</td>
                                    <td class="px-3 py-2 text-start">
                                        <x-filament::badge :color="$po->status->getColor()">{{ $po->status->getLabel() }}</x-filament::badge>
                                    </td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format((float) $po->total_amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
