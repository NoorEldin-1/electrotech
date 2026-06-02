<x-filament-panels::page>
    {{-- Operation selector --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-md">
            <label for="cost-file-operation" class="block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                {{ __('resources.operations_cost.select_operation') }}
            </label>
            <select
                id="cost-file-operation"
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

    @php($breakdown = $this->getBreakdown())

    @if ($breakdown === null)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.operations_cost.empty') }}</p>
        </x-filament::section>
    @else
        @php($profitColor = $breakdown['profit'] >= 0 ? 'success' : 'danger')
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @foreach ([
                'estimated_budget' => 'gray',
                'materials_cost' => 'info',
                'ledger_expenses' => 'info',
                'installation_expenses' => 'info',
                'purchases_reference' => 'gray',
                'total_cost' => 'warning',
                'revenue' => 'success',
                'received' => 'success',
            ] as $key => $color)
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.operations_cost.cards.' . $key) }}</div>
                    <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ number_format((float) $breakdown[$key], 2) }}
                    </div>
                </x-filament::section>
            @endforeach

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.operations_cost.cards.profit') }}</div>
                <div class="mt-1">
                    <x-filament::badge :color="$profitColor" class="text-base">
                        {{ number_format((float) $breakdown['profit'], 2) }}
                    </x-filament::badge>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.operations_cost.cards.margin') }}</div>
                <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-white">
                    @if ($breakdown['margin_percent'] === null)
                        {{ __('resources.common.no_data') }}
                    @else
                        {{ number_format((float) $breakdown['margin_percent'], 1) }}%
                    @endif
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
