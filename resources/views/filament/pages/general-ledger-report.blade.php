@php
    use App\Enums\DocumentType;

    $docColor = fn (DocumentType $type): string => match ($type) {
        DocumentType::PaymentOrder => 'text-gray-900 dark:text-[var(--dark-text)]',
        DocumentType::SupplyReceipt => 'text-red-600 dark:text-red-400',
        DocumentType::Settlement => 'text-green-600 dark:text-green-400',
    };
@endphp

<x-filament-panels::page class="et-report">
    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-sm">
            <label for="ledger-account" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.general_ledger_report.filters.account') }}
            </label>
            <select id="ledger-account" wire:model.live="accountId"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]">
                @foreach ($this->getAccounts() as $account)
                    <option value="{{ $account->id }}" class="bg-white text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">{{ $account->display_name }}</option>
                @endforeach
            </select>
        </div>

        <div class="w-full max-w-[12rem]">
            <label for="ledger-from" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.general_ledger_report.filters.from') }}
            </label>
            <input id="ledger-from" type="date" wire:model.live="from"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]" />
        </div>

        <div class="w-full max-w-[12rem]">
            <label for="ledger-to" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
                {{ __('resources.general_ledger_report.filters.to') }}
            </label>
            <input id="ledger-to" type="date" wire:model.live="to"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]" />
        </div>

        <x-filament::button tag="a" href="{{ $this->getPrintUrl() }}" target="_blank" icon="heroicon-o-printer" color="gray">
            {{ __('resources.common.print') }}
        </x-filament::button>
    </div>

    @php($ledger = $this->getLedger())

    @if (! $ledger)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.general_ledger_report.no_account') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                {{ __('resources.general_ledger_report.heading', ['account' => $ledger['account']->name]) }}
            </x-slot>

            <x-slot name="description">
                {{ __('resources.general_ledger_report.period', [
                    'from' => $this->from ? \Illuminate\Support\Carbon::parse($this->from)->translatedFormat('d M Y') : '—',
                    'to' => $this->to ? \Illuminate\Support\Carbon::parse($this->to)->translatedFormat('d M Y') : '—',
                ]) }}
            </x-slot>

            <x-slot name="headerEnd">
                <x-filament::badge color="primary">
                    {{ __('resources.general_ledger_report.closing_balance') }}: {{ number_format($ledger['closing'], 2) }} {{ $ledger['account']->currency }}
                </x-filament::badge>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.general_ledger_report.columns.date') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.general_ledger_report.columns.entry_serial') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.general_ledger_report.columns.document_number') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('resources.general_ledger_report.columns.description') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.general_ledger_report.columns.debit') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.general_ledger_report.columns.credit') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('resources.general_ledger_report.columns.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                        {{-- رصيد أول المدة: the line every statement opens with. --}}
                        <tr class="bg-gray-50 font-medium text-gray-950 dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start" colspan="4">{{ __('resources.general_ledger_report.opening_balance') }}</td>
                            <td class="px-3 py-2 text-end">—</td>
                            <td class="px-3 py-2 text-end">—</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($ledger['opening'], 2) }}</td>
                        </tr>

                        @foreach ($ledger['rows'] as $row)
                            <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                                <td class="whitespace-nowrap px-3 py-2 text-start tabular-nums">{{ $row['date']->format('d-M-y') }}</td>
                                <td class="px-3 py-2 text-start tabular-nums">{{ $row['entry_serial'] }}</td>
                                <td class="px-3 py-2 text-start font-bold tabular-nums {{ $docColor($row['document_type']) }}">
                                    {{ $row['document_number'] ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-start">{{ $row['description'] }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '' }}</td>
                                <td class="px-3 py-2 text-end tabular-nums text-red-600 dark:text-red-400">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '' }}</td>
                                <td class="px-3 py-2 text-end font-medium tabular-nums">{{ number_format($row['balance'], 2) }}</td>
                            </tr>
                        @endforeach

                        @if ($ledger['rows']->isEmpty())
                            <tr>
                                <td colspan="7" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">
                                    {{ __('resources.general_ledger_report.empty') }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-bold text-gray-950 dark:border-[var(--border-strong)] dark:text-[var(--dark-text)]">
                            <td class="px-3 py-2 text-start" colspan="4">{{ __('resources.general_ledger_report.totals') }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($ledger['totals']['debit'], 2) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($ledger['totals']['credit'], 2) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($ledger['closing'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
