{{--
    قائمة المركز المالى — ماليات.pptx سلايدات 4، 5، 6.

    Laid out like the photographed sheet: long-term assets in three columns
    (التكلفة / مجمع الإهلاك / الصافى), then current assets, then current
    liabilities down to رأس المال العامل and اجمالى الاستثمار, then "يتم تمويلة
    على النحو التالى", and finally the guarantee-cheques footnote.
--}}
<x-filament-panels::page class="et-report">
    @include('filament.pages.partials.statement-filters', ['idPrefix' => 'balance-sheet', 'asOfOnly' => true])

    @php($s = $this->getStatement())

    {{-- The trust signal first: does the sheet balance? --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('resources.balance_sheet.title') }}</x-slot>
        <x-slot name="description">
            {{ __('resources.balance_sheet.as_of_label', ['date' => $s['as_of']->format('Y-m-d')]) }}
        </x-slot>

        <x-slot name="headerEnd">
            @if ($s['balanced'])
                <x-filament::badge color="success">{{ __('resources.balance_sheet.balanced') }}</x-filament::badge>
            @else
                <x-filament::badge color="danger">
                    {{ __('resources.balance_sheet.unbalanced', ['difference' => number_format($s['difference'], 2)]) }}
                </x-filament::badge>
            @endif
        </x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'working_capital' => $s['working_capital'],
                'fixed_assets_net' => $s['fixed_assets']['net'],
                'total_investment' => $s['total_investment'],
                'total_funding' => $s['funding']['total'],
            ] as $key => $value)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-[var(--border-hairline)]">
                    <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                        {{ __('resources.balance_sheet.cards.' . $key) }}
                    </div>
                    <div class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">
                        {{ number_format($value, 2) }}
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- الأصول طويلة الأجل — three columns, straight off the client's sheet. --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('resources.balance_sheet.sections.long_term_assets') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                        <th class="px-3 py-2 text-start font-medium">{{ __('resources.financial_statements.columns.description') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.balance_sheet.columns.cost') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.balance_sheet.columns.accumulated_depreciation') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.balance_sheet.columns.net') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                    @forelse ($s['fixed_assets']['rows'] as $row)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start">
                                {{ $row['label'] }}
                                @if ($row['account']?->code)
                                    <span class="text-xs text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $row['account']->code }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['cost'], 2) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['accumulated'], 2) }}</td>
                            <td class="px-3 py-2 text-end font-medium tabular-nums">{{ number_format($row['net'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-3 text-gray-500 dark:text-[var(--dark-text-muted)]" colspan="4">
                                {{ __('resources.financial_statements.no_rows') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.balance_sheet.rows.total_long_term_assets') }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['fixed_assets']['cost'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['fixed_assets']['accumulated'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['fixed_assets']['net'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    {{-- الأصول المتداولة / الالتزامات المتداولة --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([
            ['key' => 'current_assets', 'data' => $s['current_assets'], 'total_row' => 'total_current_assets'],
            ['key' => 'current_liabilities', 'data' => $s['current_liabilities'], 'total_row' => 'total_current_liabilities'],
        ] as $block)
            <x-filament::section>
                <x-slot name="heading">{{ __('resources.balance_sheet.sections.' . $block['key']) }}</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                            @forelse ($block['data']['rows'] as $row)
                                <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                    <td class="px-3 py-2 text-start">
                                        {{ $row['label'] }}
                                        @if (($row['kind'] ?? null) === 'party_split')
                                            {{-- سلايد 7: the reclassified half, with how many parties fell on this side. --}}
                                            <x-filament::badge color="info" size="xs">
                                                {{ __('resources.balance_sheet.party.count', ['count' => count($row['parties'])]) }}
                                            </x-filament::badge>
                                        @elseif (($row['kind'] ?? null) === 'party_reconciliation')
                                            <x-filament::badge color="warning" size="xs">
                                                {{ __('resources.balance_sheet.party.variance_badge') }}
                                            </x-filament::badge>
                                        @elseif ($row['account']?->code)
                                            <span class="text-xs text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $row['account']->code }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-3 py-3 text-gray-500 dark:text-[var(--dark-text-muted)]" colspan="2">
                                        {{ __('resources.financial_statements.no_rows') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                                <td class="px-3 py-2 text-start">{{ __('resources.balance_sheet.rows.' . $block['total_row']) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($block['data']['total'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- معادلات سلايد 4 --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('resources.balance_sheet.sections.equations') }}</x-slot>

        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                    <td class="px-3 py-2 text-start">{{ __('resources.balance_sheet.rows.working_capital_formula') }}</td>
                    <td class="px-3 py-2 text-end font-bold tabular-nums">{{ number_format($s['working_capital'], 2) }}</td>
                </tr>
                <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                    <td class="px-3 py-2 text-start">{{ __('resources.balance_sheet.rows.total_investment_formula') }}</td>
                    <td class="px-3 py-2 text-end font-bold tabular-nums">{{ number_format($s['total_investment'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    </x-filament::section>

    {{-- «يتم تمويلة على النحو التالى» --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('resources.balance_sheet.sections.funding') }}</x-slot>
        <x-slot name="description">{{ __('resources.balance_sheet.sections.funding_hint') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                    @foreach ($s['funding']['rows'] as $row)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start">
                                {{ $row['label'] }}
                                @if ($row['kind'] === 'period_profit')
                                    <x-filament::badge color="success" size="xs">
                                        {{ __('resources.balance_sheet.from_income_statement') }}
                                    </x-filament::badge>
                                @elseif ($row['account']?->code)
                                    <span class="text-xs text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $row['account']->code }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.balance_sheet.rows.total_funding') }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['funding']['total'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @unless ($s['balanced'])
            <p class="mt-4 rounded-lg bg-danger-50 p-3 text-xs text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                {{ __('resources.balance_sheet.unbalanced_hint', ['difference' => number_format($s['difference'], 2)]) }}
            </p>
        @endunless
    </x-filament::section>

    {{-- حاشية سلايد 6: «يوجد شيكات ضمانه بمبلغ ...» --}}
    @if (! empty($s['memo']))
        <x-filament::section>
            <x-slot name="heading">{{ __('resources.balance_sheet.sections.memo') }}</x-slot>
            <x-slot name="description">{{ __('resources.balance_sheet.sections.memo_hint') }}</x-slot>

            <ul class="space-y-1 text-sm text-gray-950 dark:text-[var(--dark-text)]">
                @foreach ($s['memo'] as $row)
                    <li>
                        {{ __('resources.balance_sheet.memo_line', [
                            'account' => $row['account']->name,
                            'amount' => number_format($row['amount'], 2),
                        ]) }}
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- سلايد 7 — the party split laid open, party by party. --}}
    @php($partyRows = collect($s['current_assets']['rows'])->concat($s['current_liabilities']['rows'])->where('kind', 'party_split')->filter(fn ($r) => count($r['parties']) > 0))

    @if ($partyRows->isNotEmpty())
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">{{ __('resources.balance_sheet.party.detail_heading') }}</x-slot>
            <x-slot name="description">{{ __('resources.balance_sheet.party.detail_hint') }}</x-slot>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($partyRows as $row)
                    <div>
                        <div class="mb-2 text-sm font-semibold text-gray-950 dark:text-[var(--dark-text)]">{{ $row['label'] }}</div>
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                                @foreach ($row['parties'] as $party)
                                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                        <td class="px-3 py-1.5 text-start">{{ $party['party']->name }}</td>
                                        <td class="px-3 py-1.5 text-end tabular-nums">{{ number_format($party['balance'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
