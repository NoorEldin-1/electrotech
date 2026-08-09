{{--
    Shared period filter + print button for the four financial-statement pages
    (ماليات.pptx). `$idPrefix` keeps the label/input ids unique per page so the
    <label for> association still points at the right control.

    `$asOfOnly` renders the balance-sheet variant: the period start is still
    bound (أرباح الفترة needs it) but the emphasis is on the "as of" date the
    position is stated at.
--}}
@php($idPrefix = $idPrefix ?? 'statement')
@php($asOfOnly = $asOfOnly ?? false)

<div class="flex flex-wrap items-end gap-4">
    <div class="w-full max-w-[12rem]">
        <label for="{{ $idPrefix }}-from" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
            {{ $asOfOnly ? __('resources.financial_statements.filters.period_from') : __('resources.financial_statements.filters.from') }}
        </label>
        <input id="{{ $idPrefix }}-from" type="date" wire:model.live="from"
            class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]" />
    </div>

    <div class="w-full max-w-[12rem]">
        <label for="{{ $idPrefix }}-to" class="block text-sm font-medium leading-6 text-gray-950 dark:text-[var(--dark-text)]">
            {{ $asOfOnly ? __('resources.financial_statements.filters.as_of') : __('resources.financial_statements.filters.to') }}
        </label>
        <input id="{{ $idPrefix }}-to" type="date" wire:model.live="to"
            class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)] dark:text-[var(--dark-text)] dark:[color-scheme:dark]" />
    </div>

    <x-filament::button tag="a" href="{{ $this->getPrintUrl() }}" target="_blank" icon="heroicon-o-printer" color="gray">
        {{ __('resources.common.print') }}
    </x-filament::button>
</div>
