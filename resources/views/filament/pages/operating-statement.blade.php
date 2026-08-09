{{--
    قائمة التشغيل — ماليات.pptx سلايد 2. One row per cost-of-sales account,
    summing to تكلفة المبيعات, exactly as the slide lays it out.
--}}
<x-filament-panels::page class="et-report">
    @include('filament.pages.partials.statement-filters', ['idPrefix' => 'operating'])

    @php($statement = $this->getStatement())

    <x-filament::section>
        <x-slot name="heading">{{ __('resources.operating_statement.title') }}</x-slot>
        <x-slot name="description">{{ __('resources.operating_statement.description') }}</x-slot>

        @if (empty($statement['rows']))
            <p class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">
                {{ __('resources.operating_statement.empty') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.financial_statements.columns.line') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.financial_statements.columns.account_code') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.financial_statements.columns.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                        @foreach ($statement['rows'] as $index => $row)
                            <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                <td class="px-3 py-2 text-start">
                                    {{ $index === 0 ? '' : '+ ' }}{{ $row['account']->name }}
                                </td>
                                <td class="px-3 py-2 text-start text-gray-400 dark:text-[var(--dark-text-faint)]">
                                    {{ $row['account']->code ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start" colspan="2">{{ __('resources.operating_statement.cost_of_sales') }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($statement['total'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- The slide's own closing note: every line above is a real
                 account, so a missing line means a missing account. --}}
            <p class="mt-4 text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                {{ __('resources.operating_statement.chart_note') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
