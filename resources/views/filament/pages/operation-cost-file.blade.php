<x-filament-panels::page class="et-report">
    {{-- Operation selector --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-md">
            <label for="cost-file-operation" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.operations_cost.select_operation') }}
            </label>
            <select
                id="cost-file-operation"
                wire:model.live="projectId"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]"
            >
                <option value="" class="bg-white text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">—</option>
                @foreach ($this->getProjectOptions() as $id => $label)
                    <option value="{{ $id }}" class="bg-white text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @php($breakdown = $this->getBreakdown())

    @if ($breakdown === null)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operations_cost.empty') }}</p>
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
                    <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operations_cost.cards.' . $key) }}</div>
                    <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">
                        {{ number_format((float) $breakdown[$key], 2) }}
                    </div>
                </x-filament::section>
            @endforeach

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operations_cost.cards.profit') }}</div>
                <div class="mt-1">
                    <x-filament::badge :color="$profitColor" class="text-base">
                        {{ number_format((float) $breakdown['profit'], 2) }}
                    </x-filament::badge>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operations_cost.cards.margin') }}</div>
                <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">
                    @if ($breakdown['margin_percent'] === null)
                        {{ __('resources.common.no_data') }}
                    @else
                        {{ number_format((float) $breakdown['margin_percent'], 1) }}%
                    @endif
                </div>
            </x-filament::section>
        </div>

        {{-- سلايد 12: إقفال مركز التكلفة في ح/تكلفة البضاعة المباعة --}}
        @php($closingState = $this->getClosingState())
        @php($closings = $this->getClosings())

        <x-filament::section>
            <x-slot name="heading">{{ __('resources.operations_cost.closing.heading') }}</x-slot>
            <x-slot name="description">{{ __('resources.operations_cost.closing.description') }}</x-slot>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach ([
                    'inventory_consumed',
                    'closed_to_cogs',
                    'unclosed_cost',
                ] as $key)
                    <div>
                        <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operations_cost.cards.' . $key) }}</div>
                        <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">
                            {{ number_format((float) $breakdown[$key], 2) }}
                        </div>
                    </div>
                @endforeach

                <div>
                    <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operations_cost.closing.status') }}</div>
                    <div class="mt-1">
                        <x-filament::badge :color="$closingState['color']">
                            {{ __('resources.operations_cost.closing.states.' . $closingState['key']) }}
                        </x-filament::badge>
                    </div>
                </div>
            </div>

            @if ($closings->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">
                    {{ __('resources.operations_cost.closing.empty') }}
                </p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-start text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                                @foreach (['date', 'amount', 'entry', 'delivery', 'by', 'notes'] as $column)
                                    <th class="py-2 text-start font-medium">{{ __('resources.operations_cost.closing.columns.' . $column) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($closings as $closing)
                                <tr class="border-b border-gray-100 text-gray-950 last:border-0 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text)]">
                                    <td class="py-2 tabular-nums">{{ $closing->closed_at?->format('Y-m-d') }}</td>
                                    <td class="py-2 tabular-nums">
                                        <x-filament::badge :color="$closing->isReversal() ? 'danger' : 'success'">
                                            {{ number_format((float) $closing->amount, 2) }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2">{{ $closing->journalEntry?->entry_number ?? '—' }}</td>
                                    <td class="py-2">{{ $closing->deliveryVoucher?->voucher_number ?? '—' }}</td>
                                    <td class="py-2">
                                        {{ $closing->closedBy?->name ?? __('resources.operations_cost.closing.automatic') }}
                                    </td>
                                    <td class="py-2 text-gray-500 dark:text-[var(--dark-text-muted)]">{{ $closing->notes ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
