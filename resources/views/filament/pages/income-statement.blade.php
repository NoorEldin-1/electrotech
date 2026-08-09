{{--
    قائمة الدخل — ماليات.pptx سلايد 3, reproduced row for row including the two
    numeric columns the client's table uses:

        جزئى — a component line
        كلى  — a subtotal (مجمل الربح، اجمالى الايرادات، اجمالى المصروفات، صافى الربح)
--}}
<x-filament-panels::page class="et-report">
    @include('filament.pages.partials.statement-filters', ['idPrefix' => 'income'])

    {{-- Block form throughout this file, never the inline @php(…) form: Blade
         extracts raw blocks with a non-greedy `@php … @endphp` match, so an
         inline directive appearing BEFORE a block swallows everything between
         the two. --}}
    @php
        $s = $this->getStatement();
        // Hoisted: a `>=` inside a component attribute would close the tag.
        $profitColor = $s['net_profit'] < 0 ? 'danger' : 'success';
    @endphp

    <x-filament::section>
        <x-slot name="heading">{{ __('resources.income_statement.title') }}</x-slot>
        <x-slot name="description">{{ __('resources.income_statement.description') }}</x-slot>

        <x-slot name="headerEnd">
            <x-filament::badge :color="$profitColor">
                {{ __('resources.income_statement.rows.net_profit') }}: {{ number_format($s['net_profit'], 2) }}
            </x-filament::badge>
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                        <th class="px-3 py-2 text-start font-medium">{{ __('resources.financial_statements.columns.description') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.financial_statements.columns.partial') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('resources.financial_statements.columns.total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                    {{-- صافى المبيعات (حساب المبيعات – المردودات) --}}
                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">
                            {{ __('resources.income_statement.rows.net_sales') }}
                            <span class="text-xs text-gray-400 dark:text-[var(--dark-text-faint)]">
                                ({{ number_format($s['net_sales']['sales'], 2) }} − {{ number_format($s['net_sales']['returns'], 2) }})
                            </span>
                        </td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['net_sales']['net'], 2) }}</td>
                        <td></td>
                    </tr>

                    {{-- ( - ) تكلفة المبيعات — the total of قائمة التشغيل --}}
                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">( − ) {{ __('resources.income_statement.rows.cost_of_sales') }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['cost_of_sales'], 2) }}</td>
                        <td></td>
                    </tr>

                    <tr class="bg-gray-50 font-semibold text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.income_statement.rows.gross_profit') }}</td>
                        <td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['gross_profit'], 2) }}</td>
                    </tr>

                    {{-- ( + ) الإيرادات --}}
                    @foreach ($s['revenues']['rows'] as $row)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start">( + ) {{ __('resources.income_statement.rows.' . $row['label']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                            <td></td>
                        </tr>
                    @endforeach

                    <tr class="bg-gray-50 font-semibold text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.income_statement.rows.total_revenues') }}</td>
                        <td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['revenues']['total'], 2) }}</td>
                    </tr>

                    {{-- ( - ) المصروفات. The slide notes the minus sign shows the
                         account's nature only — the figures are positive and get
                         subtracted once, in the total column. --}}
                    @foreach ($s['expenses']['rows'] as $row)
                        <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start">( − ) {{ __('resources.income_statement.rows.' . $row['label']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                            <td></td>
                        </tr>
                    @endforeach

                    <tr class="bg-gray-50 font-semibold text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ __('resources.income_statement.rows.total_expenses') }}</td>
                        <td></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($s['expenses']['total'], 2) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 text-base font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                        <td class="px-3 py-3 text-start">{{ __('resources.income_statement.rows.net_profit') }}</td>
                        <td></td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ number_format($s['net_profit'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
            {{ __('resources.income_statement.formula_note') }}
        </p>
    </x-filament::section>

    {{-- The detail behind each aggregated line, so a number can always be
         traced back to the accounts that produced it. --}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('resources.income_statement.detail_heading') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                    @php
                        $groups = [
                            ['label' => __('resources.income_statement.rows.sales'), 'rows' => $s['net_sales']['sales_accounts']],
                            ['label' => __('resources.income_statement.rows.sales_returns'), 'rows' => $s['net_sales']['returns_accounts']],
                            ['label' => __('resources.income_statement.rows.cost_of_sales'), 'rows' => $s['cost_of_sales_rows']],
                        ];
                        foreach ($s['revenues']['rows'] as $r) {
                            $groups[] = ['label' => __('resources.income_statement.rows.' . $r['label']), 'rows' => $r['accounts']];
                        }
                        foreach ($s['expenses']['rows'] as $r) {
                            $groups[] = ['label' => __('resources.income_statement.rows.' . $r['label']), 'rows' => $r['accounts']];
                        }
                    @endphp

                    @foreach ($groups as $group)
                        @if (! empty($group['rows']))
                            <tr class="bg-gray-50 text-xs font-semibold uppercase text-gray-500 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text-muted)]">
                                <td class="px-3 py-2 text-start" colspan="3">{{ $group['label'] }}</td>
                            </tr>
                            @foreach ($group['rows'] as $row)
                                <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                    <td class="px-3 py-2 ps-8 text-start">{{ $row['account']->name }}</td>
                                    <td class="px-3 py-2 text-start text-gray-400 dark:text-[var(--dark-text-faint)]">{{ $row['account']->code ?? '—' }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
