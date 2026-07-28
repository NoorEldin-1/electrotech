{{--
    شريط توازن القيد — يظهر أعلى قسم السطور فيقرأ المحاسب حالة القيد وهو بيكتب
    من غير ما ينزل لآخر الشاشة (قيود اليومية — الإدخال السريع).
--}}
<div class="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm dark:border-[var(--border-hairline)] dark:bg-[var(--surface-2)]">
    <span class="text-gray-500 dark:text-[var(--dark-text-muted)]">
        {{ __('resources.journal_entries.placeholders.total_debit') }}:
        <strong class="font-semibold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">{{ number_format($debit, 2) }}</strong>
    </span>

    <span class="text-gray-500 dark:text-[var(--dark-text-muted)]">
        {{ __('resources.journal_entries.placeholders.total_credit') }}:
        <strong class="font-semibold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">{{ number_format($credit, 2) }}</strong>
    </span>

    <span class="ms-auto">
        @if ($balanced)
            <span class="inline-flex items-center gap-1 rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30">
                <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4" />
                {{ __('resources.journal_entries.placeholders.balanced') }}
            </span>
        @else
            <span class="inline-flex items-center gap-1 rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4" />
                {{ __('resources.journal_entries.placeholders.difference') }}:
                <strong class="font-semibold tabular-nums">{{ number_format($difference, 2) }}</strong>
            </span>
        @endif
    </span>
</div>
