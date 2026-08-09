{{--
    قائمة التدفقات النقدية — ماليات.pptx سلايدات 8 و 9.

    Reads top to bottom exactly like the client's sheet: net profit, the
    non-cash adjustments, operating profit before the working-capital change,
    the working-capital lines, then investing, then financing, then the closing
    cash reconciliation.

    Every working-capital row carries the case number (1–4) from the slide, so
    the reader can check the rule the system applied against the rule on paper.
--}}
<x-filament-panels::page class="et-report">
    @include('filament.pages.partials.statement-filters', ['idPrefix' => 'cash-flow'])

    {{-- Block form throughout this file, never the inline @php(…) form: Blade
         extracts raw blocks with a non-greedy `@php … @endphp` match, so an
         inline directive appearing BEFORE a block swallows everything between
         the two. --}}
    @php
        $s = $this->getStatement();

        $money = fn (float $v): string => number_format($v, 2);
        // A negative contribution prints in parentheses, the way the sheet does.
        $signed = fn (float $v): string => $v < 0
            ? '(' . number_format(abs($v), 2) . ')'
            : number_format($v, 2);
    @endphp

    <x-filament::section>
        <x-slot name="heading">{{ __('resources.cash_flow_statement.title') }}</x-slot>
        <x-slot name="description">{{ __('resources.cash_flow_statement.description') }}</x-slot>

        <x-slot name="headerEnd">
            @if ($s['reconciled'])
                <x-filament::badge color="success">{{ __('resources.cash_flow_statement.reconciled') }}</x-filament::badge>
            @else
                <x-filament::badge color="danger">
                    {{ __('resources.cash_flow_statement.not_reconciled', ['difference' => $money($s['reconciliation_difference'])]) }}
                </x-filament::badge>
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                        <th class="px-3 py-2 text-start font-medium">{{ __('resources.financial_statements.columns.description') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.cash_flow_statement.columns.opening') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.cash_flow_statement.columns.closing') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.cash_flow_statement.columns.effect') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                    {{-- التدفقات النقدية من أنشطة التشغيل --}}
                    <tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text-muted)]">
                        <td class="px-3 py-2 text-start" colspan="4">{{ __('resources.cash_flow_statement.sections.operating') }}</td>
                    </tr>

                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">
                            {{ __('resources.cash_flow_statement.rows.net_profit') }}
                            <x-filament::badge color="success" size="xs">
                                {{ __('resources.cash_flow_statement.from_income_statement') }}
                            </x-filament::badge>
                        </td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $signed($s['net_profit']) }}</td>
                    </tr>

                    @foreach ($s['adjustments'] as $adjustment)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 ps-6 text-start">
                                {{ ['( − )', '( · )', '( + )'][$adjustment['sign'] <=> 0] }}
                                {{ __('resources.cash_flow_statement.rows.' . $adjustment['label']) }}
                                @if ($adjustment['sign'] === 0)
                                    <x-filament::badge color="gray" size="xs">
                                        {{ __('resources.cash_flow_statement.no_adjustment') }}
                                    </x-filament::badge>
                                @endif
                            </td>
                            <td></td><td></td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $signed($adjustment['amount']) }}</td>
                        </tr>
                    @endforeach

                    <tr class="bg-gray-50 font-semibold text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.cash_flow_statement.rows.operating_profit_before_wc') }}</td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $signed($s['operating_profit_before_wc']) }}</td>
                    </tr>

                    {{-- التغير في رأس المال العامل — النقص (الزيادة) --}}
                    @foreach ($s['working_capital'] as $row)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 ps-6 text-start">
                                {{ __('resources.cash_flow_statement.rows.change_in', ['account' => $row['label']]) }}
                                <x-filament::badge :color="$row['is_asset'] ? 'info' : 'warning'" size="xs">
                                    {{ __('resources.cash_flow_statement.case', ['case' => $row['case']]) }}
                                </x-filament::badge>
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $money($row['opening']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $money($row['closing']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $signed($row['amount']) }}</td>
                        </tr>
                    @endforeach

                    <tr class="bg-gray-50 font-semibold text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.cash_flow_statement.rows.operating_cash') }}</td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $signed($s['operating_cash']) }}</td>
                    </tr>

                    {{-- أنشطة الاستثمار --}}
                    <tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text-muted)]">
                        <td class="px-3 py-2 text-start" colspan="4">{{ __('resources.cash_flow_statement.sections.investing') }}</td>
                    </tr>

                    @forelse ($s['investing'] as $row)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 ps-6 text-start">{{ __('resources.cash_flow_statement.rows.change_in', ['account' => $row['label']]) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $money($row['opening']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $money($row['closing']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $signed($row['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-2 ps-6 text-gray-500 dark:text-[var(--dark-text-muted)]" colspan="4">{{ __('resources.financial_statements.no_movement') }}</td></tr>
                    @endforelse

                    <tr class="bg-gray-50 font-semibold text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.cash_flow_statement.rows.investing_total') }}</td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $signed($s['investing_total']) }}</td>
                    </tr>

                    {{-- أنشطة التمويل --}}
                    <tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text-muted)]">
                        <td class="px-3 py-2 text-start" colspan="4">{{ __('resources.cash_flow_statement.sections.financing') }}</td>
                    </tr>

                    @forelse ($s['financing'] as $row)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 ps-6 text-start">{{ __('resources.cash_flow_statement.rows.change_in', ['account' => $row['label']]) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $money($row['opening']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $money($row['closing']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $signed($row['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-2 ps-6 text-gray-500 dark:text-[var(--dark-text-muted)]" colspan="4">{{ __('resources.financial_statements.no_movement') }}</td></tr>
                    @endforelse

                    <tr class="bg-gray-50 font-semibold text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.cash_flow_statement.rows.financing_total') }}</td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $signed($s['financing_total']) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.cash_flow_statement.rows.net_change') }}</td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $signed($s['net_change']) }}</td>
                    </tr>
                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.cash_flow_statement.rows.opening_cash') }}</td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $money($s['opening_cash']) }}</td>
                    </tr>
                    <tr class="text-base font-bold text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.cash_flow_statement.rows.closing_cash') }}</td>
                        <td></td><td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $money($s['derived_closing_cash']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    {{-- «ويتم مطابقته مع الواقع» — the check that makes the statement credible. --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('resources.cash_flow_statement.reconciliation.heading') }}</x-slot>
        <x-slot name="description">{{ __('resources.cash_flow_statement.reconciliation.description') }}</x-slot>

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                'derived' => $s['derived_closing_cash'],
                'actual' => $s['actual_closing_cash'],
                'difference' => $s['reconciliation_difference'],
            ] as $key => $value)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-[var(--border-hairline)]">
                    <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                        {{ __('resources.cash_flow_statement.reconciliation.' . $key) }}
                    </div>
                    <div @class([
                        'mt-1 text-lg font-bold tabular-nums',
                        'text-danger-600 dark:text-danger-400' => $key === 'difference' && ! $s['reconciled'],
                        'text-gray-950 dark:text-[var(--dark-text)]' => ! ($key === 'difference' && ! $s['reconciled']),
                    ])>
                        {{ $money($value) }}
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Says out loud which of the two formulas the statement is running, so
         nobody has to guess whether depreciation was added or deducted.

         Rendered as a contained callout rather than loose text at the foot of
         the page. Kept to product language: the configuration key that swaps
         the formulas is documented in config/finance.php, which is where a
         developer looks — not on the accountant's screen. --}}
    @php($variant = $s['add_back_non_cash'] ? 'add_back' : 'client')

    <x-filament::section>
        <div class="flex items-start gap-3">
            <x-filament::icon
                icon="heroicon-o-information-circle"
                @class([
                    'mt-0.5 size-5 shrink-0',
                    'text-primary-500' => $s['add_back_non_cash'],
                    'text-warning-500' => ! $s['add_back_non_cash'],
                ])
            />

            <div class="space-y-1">
                <p class="text-sm font-medium text-gray-950 dark:text-[var(--dark-text)]">
                    {{ __('resources.cash_flow_statement.formula_note.heading_' . $variant) }}
                </p>

                <p class="text-xs leading-relaxed text-gray-500 dark:text-[var(--dark-text-muted)]">
                    {{ __('resources.cash_flow_statement.formula_note.body_' . $variant) }}
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
