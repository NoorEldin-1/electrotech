@php
    use App\Enums\DocumentType;

    /**
     * Document numbers are colour-coded by type, the way the paper daybook is
     * read. Black stays readable in dark mode by falling back to the theme's
     * text token instead of a hard #000.
     */
    $docColor = fn (DocumentType $type): string => match ($type) {
        DocumentType::PaymentOrder => 'text-gray-900 dark:text-[var(--dark-text)]',
        DocumentType::SupplyReceipt => 'text-red-600 dark:text-red-400',
        DocumentType::Settlement => 'text-green-600 dark:text-green-400',
    };
@endphp

<x-filament-panels::page class="et-report">
    @php($daybook = $this->getDaybook())

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-[11rem]">
            <label for="daybook-from" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.journal_daybook.filters.from') }}
            </label>
            <input id="daybook-from" type="date" wire:model.live="from"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]" />
        </div>

        <div class="w-full max-w-[11rem]">
            <label for="daybook-to" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.journal_daybook.filters.to') }}
            </label>
            <input id="daybook-to" type="date" wire:model.live="to"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]" />
        </div>

        <div class="w-full max-w-[9rem]">
            <label for="daybook-currency" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.journal_daybook.filters.currency') }}
            </label>
            <select id="daybook-currency" wire:model.live="currency"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]">
                @foreach ($this->getCurrencies() as $currency)
                    <option value="{{ $currency }}" class="bg-white text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">{{ $currency }}</option>
                @endforeach
            </select>
        </div>

        <x-filament::button tag="a" href="{{ $this->getPrintUrl() }}" target="_blank" icon="heroicon-o-printer" color="gray">
            {{ __('resources.common.print') }}
        </x-filament::button>
    </div>

    {{--
        Account columns. A native <select multiple> renders badly inside the
        panel theme (stacked arrows, unreadable options), so the accounts are
        picked as checkboxes in a compact scrollable box instead — and the ones
        past the column cap are disabled rather than silently dropped.
    --}}
    <x-filament::section collapsible :collapsed="filled($this->accountIds)" >
        <x-slot name="heading">{{ __('resources.journal_daybook.filters.accounts') }}</x-slot>

        <x-slot name="description">
            {{ __('resources.journal_daybook.filters.accounts_hint', ['max' => \App\Services\JournalDaybookService::MAX_COLUMNS]) }}
        </x-slot>

        <x-slot name="headerEnd">
            @if (filled($this->accountIds))
                <x-filament::button size="xs" color="gray" wire:click="clearAccounts">
                    {{ __('resources.journal_daybook.filters.clear_accounts') }}
                </x-filament::button>
            @endif
        </x-slot>

        @php($atCap = count($this->accountIds) >= \App\Services\JournalDaybookService::MAX_COLUMNS)

        @if ($daybook['available_accounts']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.journal_daybook.empty') }}</p>
        @else
            <div class="grid max-h-44 grid-cols-1 gap-x-6 gap-y-2 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($daybook['available_accounts'] as $account)
                    @php($checked = in_array((string) $account->id, array_map('strval', $this->accountIds), true))
                    <label class="flex items-center gap-2 text-sm {{ ! $checked && $atCap ? 'opacity-40' : '' }} text-gray-950 dark:text-[var(--dark-text)]">
                        <input type="checkbox" value="{{ $account->id }}" wire:model.live="accountIds"
                            @disabled(! $checked && $atCap)
                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)]" />
                        <span class="truncate">{{ $account->display_name }}</span>
                    </label>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            {{ __('resources.journal_daybook.title') }} — {{ $this->currency }}
        </x-slot>

        <x-slot name="description">
            {{ __('resources.journal_daybook.period', [
                'from' => $this->from ? \Illuminate\Support\Carbon::parse($this->from)->translatedFormat('d M Y') : '—',
                'to' => $this->to ? \Illuminate\Support\Carbon::parse($this->to)->translatedFormat('d M Y') : '—',
            ]) }}
        </x-slot>

        @if ($daybook['rows']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.journal_daybook.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                            <th rowspan="2" class="px-3 py-2 text-start font-medium">{{ __('resources.journal_daybook.columns.date') }}</th>
                            <th rowspan="2" class="px-3 py-2 text-start font-medium">{{ __('resources.journal_daybook.columns.entry_serial') }}</th>
                            <th rowspan="2" class="px-3 py-2 text-start font-medium">{{ __('resources.journal_daybook.columns.document_number') }}</th>
                            <th rowspan="2" class="px-3 py-2 text-start font-medium">{{ __('resources.journal_daybook.columns.description') }}</th>
                            @foreach ($daybook['accounts'] as $account)
                                <th colspan="2" class="border-s border-gray-200 px-3 py-2 text-center font-medium dark:border-[var(--border-hairline)]">
                                    {{ $account->name }}
                                </th>
                            @endforeach
                            <th colspan="2" class="border-s-2 border-gray-300 px-3 py-2 text-center font-medium dark:border-[var(--border-strong)]">
                                {{ __('resources.journal_daybook.columns.entry_totals') }}
                            </th>
                        </tr>
                        <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                            @foreach ($daybook['accounts'] as $account)
                                <th class="border-s border-gray-200 px-3 py-1 text-end font-medium dark:border-[var(--border-hairline)]">{{ __('resources.journal_daybook.columns.debit') }}</th>
                                <th class="px-3 py-1 text-end font-medium">{{ __('resources.journal_daybook.columns.credit') }}</th>
                            @endforeach
                            <th class="border-s-2 border-gray-300 px-3 py-1 text-end font-medium dark:border-[var(--border-strong)]">{{ __('resources.journal_daybook.columns.debit') }}</th>
                            <th class="px-3 py-1 text-end font-medium">{{ __('resources.journal_daybook.columns.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                        @foreach ($daybook['rows'] as $row)
                            @php($entry = $row['entry'])
                            <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                <td class="whitespace-nowrap px-3 py-2 text-start tabular-nums">{{ $entry->entry_date?->format('d-M-y') }}</td>
                                <td class="px-3 py-2 text-start tabular-nums">{{ $entry->entry_serial }}</td>
                                <td class="px-3 py-2 text-start font-bold tabular-nums {{ $docColor($entry->document_type) }}">
                                    {{ $entry->document_number ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-start">{{ $entry->description }}</td>
                                @foreach ($daybook['accounts'] as $account)
                                    @php($cell = $row['cells'][$account->id] ?? ['debit' => 0.0, 'credit' => 0.0])
                                    <td class="border-s border-gray-200 px-3 py-2 text-end tabular-nums dark:border-[var(--border-hairline)]">
                                        {{ $cell['debit'] > 0 ? number_format($cell['debit'], 2) : '' }}
                                    </td>
                                    <td class="px-3 py-2 text-end tabular-nums text-red-600 dark:text-red-400">
                                        {{ $cell['credit'] > 0 ? number_format($cell['credit'], 2) : '' }}
                                    </td>
                                @endforeach
                                <td class="border-s-2 border-gray-300 px-3 py-2 text-end font-medium tabular-nums dark:border-[var(--border-strong)]">{{ number_format($row['total_debit'], 2) }}</td>
                                <td class="px-3 py-2 text-end font-medium tabular-nums">{{ number_format($row['total_credit'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                            <td colspan="4" class="px-3 py-2 text-start">{{ __('resources.journal_daybook.totals') }}</td>
                            @foreach ($daybook['accounts'] as $account)
                                @php($totals = $daybook['column_totals'][$account->id] ?? ['debit' => 0.0, 'credit' => 0.0])
                                <td class="border-s border-gray-200 px-3 py-2 text-end tabular-nums dark:border-[var(--border-hairline)]">{{ number_format($totals['debit'], 2) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($totals['credit'], 2) }}</td>
                            @endforeach
                            <td class="border-s-2 border-gray-300 px-3 py-2 text-end tabular-nums dark:border-[var(--border-strong)]">{{ number_format($daybook['total_debit'], 2) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($daybook['total_credit'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
