<x-filament-panels::page>
    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="trial-balance-as-of" class="block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                {{ __('resources.trial_balance.as_of') }}
            </label>
            <input
                id="trial-balance-as-of"
                type="date"
                wire:model.live="asOf"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
            />
        </div>
    </div>

    @php($groups = $this->getGroups())

    @if ($groups->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('resources.trial_balance.empty') }}</p>
        </x-filament::section>
    @endif

    @foreach ($groups as $currency => $group)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('resources.trial_balance.title') }} — {{ $currency }}
            </x-slot>

            <x-slot name="headerEnd">
                @if ($group['balanced'])
                    <x-filament::badge color="success">{{ __('resources.trial_balance.balanced') }}</x-filament::badge>
                @else
                    <x-filament::badge color="danger">{{ __('resources.trial_balance.unbalanced') }}</x-filament::badge>
                @endif
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.trial_balance.columns.row') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.trial_balance.columns.account') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.trial_balance.columns.debit') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.trial_balance.columns.credit') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.trial_balance.columns.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($group['rows'] as $index => $row)
                            <tr class="text-gray-950 dark:text-white">
                                <td class="px-3 py-2 text-start tabular-nums text-gray-500 dark:text-gray-400">{{ $index + 1 }}</td>
                                <td class="px-3 py-2 text-start">
                                    @if ($row['account']->code)
                                        <span class="text-gray-400 dark:text-gray-500">{{ $row['account']->code }}</span>
                                    @endif
                                    {{ $row['account']->name }}
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['debit'], 2) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['credit'], 2) }}</td>
                                <td class="px-3 py-2 text-end font-medium tabular-nums">{{ number_format($row['balance'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-white/20 dark:text-white">
                            <td class="px-3 py-2 text-start" colspan="2">{{ __('resources.trial_balance.totals') }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($group['total_debit'], 2) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($group['total_credit'], 2) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $currency }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
