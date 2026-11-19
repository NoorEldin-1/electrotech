<x-filament-panels::page class="et-report">
    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="inventory-valuation-as-of" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.inventory_valuation.as_of') }}
            </label>
            <input
                id="inventory-valuation-as-of"
                type="date"
                wire:model.live="asOf"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]"
            />
        </div>
    </div>

    @php($valuation = $this->getValuation())

    @if ($valuation['missing_account_codes'] !== [])
        <x-filament::section>
            <p class="text-sm text-warning-600 dark:text-warning-400">
                {{ __('resources.inventory_valuation.missing_accounts', ['codes' => implode(' / ', $valuation['missing_account_codes'])]) }}
            </p>
        </x-filament::section>
    @endif

    {{-- Cards: the grand total, then each inventory account's ledger balance --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.inventory_valuation.cards.total') }}</div>
            <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-[var(--dark-text)]" data-card="total">
                {{ number_format($valuation['totals']['ledger'], 2) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.inventory_valuation.cards.total_hint') }}</div>
        </x-filament::section>

        @foreach ($valuation['stores'] as $store)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">
                    @if ($store['account']?->code)
                        <span class="text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $store['account']->code }}</span>
                    @endif
                    {{ $store['warehouse']->getLabel() }}
                </div>
                <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-[var(--dark-text)]" data-card="{{ $store['key'] }}">
                    @if ($store['ledger'] === null)
                        {{ __('resources.common.no_data') }}
                    @else
                        {{ number_format($store['ledger'], 2) }}
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- Ledger vs stock card --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('resources.inventory_valuation.reconciliation.heading') }}</x-slot>
        <x-slot name="description">{{ __('resources.inventory_valuation.reconciliation.description') }}</x-slot>

        <x-slot name="headerEnd">
            @if ($valuation['totals']['reconciled'])
                <x-filament::badge color="success">{{ __('resources.inventory_valuation.reconciliation.matched') }}</x-filament::badge>
            @else
                <x-filament::badge color="warning">{{ __('resources.inventory_valuation.reconciliation.differs') }}</x-filament::badge>
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                        <th class="px-3 py-2 text-start font-medium">{{ __('resources.inventory_valuation.columns.store') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.inventory_valuation.columns.ledger') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.inventory_valuation.columns.stock_card') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.inventory_valuation.columns.difference') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                    @foreach ($valuation['stores'] as $store)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start">
                                @if ($store['account']?->code)
                                    <span class="text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $store['account']->code }}</span>
                                @endif
                                {{ $store['warehouse']->getLabel() }}
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $store['ledger'] === null ? '—' : number_format($store['ledger'], 2) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($store['stock_card'], 2) }}</td>
                            <td class="px-3 py-2 text-end font-medium tabular-nums {{ $store['difference'] !== null && ! $store['reconciled'] ? 'text-warning-600 dark:text-warning-400' : '' }}">
                                {{ $store['difference'] === null ? '—' : number_format($store['difference'], 2) }}
                            </td>
                        </tr>
                    @endforeach

                    {{-- The old, un-split inventory account (ruling R2) --}}
                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">
                            @if ($valuation['legacy']['account']?->code)
                                <span class="text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $valuation['legacy']['account']->code }}</span>
                            @endif
                            {{ __('resources.inventory_valuation.legacy_row') }}
                        </td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($valuation['legacy']['balance'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">—</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($valuation['legacy']['balance'], 2) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.inventory_valuation.totals') }}</td>
                        <td class="px-3 py-2 text-end tabular-nums" data-total="ledger">{{ number_format($valuation['totals']['ledger'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums" data-total="stock_card">{{ number_format($valuation['totals']['stock_card'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums" data-total="difference">{{ number_format($valuation['totals']['difference'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @unless ($valuation['totals']['reconciled'])
            <p class="mt-3 text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">
                {{ __('resources.inventory_valuation.reconciliation.hint') }}
            </p>
        @endunless
    </x-filament::section>

    {{-- Work in progress per operation --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('resources.inventory_valuation.wip.heading') }}</x-slot>
        <x-slot name="description">{{ __('resources.inventory_valuation.wip.description') }}</x-slot>

        @if ($valuation['wip_operations'] === [])
            <p class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.inventory_valuation.wip.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.inventory_valuation.columns.operation') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.inventory_valuation.columns.ledger') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.inventory_valuation.columns.stock_card') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.inventory_valuation.columns.difference') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                        @foreach ($valuation['wip_operations'] as $row)
                            <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                <td class="px-3 py-2 text-start">
                                    @if ($row['project'] === null)
                                        {{ __('resources.inventory_valuation.wip.unallocated') }}
                                    @else
                                        @if ($row['project']['code'])
                                            <span class="text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $row['project']['code'] }}</span>
                                        @endif
                                        {{ $row['project']['name'] }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['ledger'], 2) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['stock_card'], 2) }}</td>
                                <td class="px-3 py-2 text-end font-medium tabular-nums {{ abs($row['difference']) >= 0.01 ? 'text-warning-600 dark:text-warning-400' : '' }}">
                                    {{ number_format($row['difference'], 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Help: how the legacy 1300 balance is reclassified (ruling R2) --}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('resources.inventory_valuation.help.heading') }}</x-slot>

        <div class="space-y-2 text-sm text-gray-600 dark:text-[var(--dark-text-muted)]">
            <p>{{ __('resources.inventory_valuation.help.ledger') }}</p>
            <p>{{ __('resources.inventory_valuation.help.stock_card') }}</p>
            <p>{{ __('resources.inventory_valuation.help.reclassify') }}</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
