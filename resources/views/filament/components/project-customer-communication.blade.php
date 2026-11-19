{{--
    التواصل مع العميل (Final_Modifications_Plan 1.1) — the latest calls /
    meetings / messages on the operation file, a Forms View field. Reads the
    ordinary customer_interactions log; the button mounts the page action that
    CustomerInteractionResource::logCommunicationAction() registers on
    ViewProject / EditProject.
--}}
@php($record = $getRecord())
{{-- The calls and notes are the customer-interactions log: without
     customer_interactions.view nothing of it is read or shown, whatever section
     this view ends up in. --}}
@if ($record && (auth()->user()?->can('customer_interactions.view') ?? false))
    @php($canLog = auth()->user()?->can('customer_interactions.manage') ?? false)
    @php($feed = app(\App\Services\OperationTimelineService::class)->interactionsFor($record, 5))

    <div class="et-report space-y-3">
        @if ($canLog)
            <div>
                <x-filament::button
                    type="button"
                    size="sm"
                    icon="heroicon-o-phone-arrow-up-right"
                    wire:click="mountAction('{{ \App\Filament\Resources\CustomerInteractionResource::LOG_ACTION }}')"
                >
                    {{ __('resources.customer_communication.actions.log') }}
                </x-filament::button>
            </div>
        @endif

        @forelse ($feed['items'] as $item)
            <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-[var(--surface-2)]">
                <div class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                    <span class="tabular-nums">{{ $item['at']->format('Y-m-d H:i') }}</span>
                    <span>·</span>
                    <span>{{ $item['channel_label'] ?? $item['type_label'] }}</span>
                    @if ($item['recorded_by'])
                        <span>·</span>
                        <span>{{ $item['recorded_by'] }}</span>
                    @endif
                </div>

                @if ($item['summary'])
                    <div class="mt-1 font-medium text-gray-950 dark:text-[var(--dark-text)]">{{ $item['summary'] }}</div>
                @endif

                @if ($item['notes'])
                    <div class="mt-1 whitespace-pre-line text-gray-700 dark:text-[var(--dark-text-muted)]">{{ $item['notes'] }}</div>
                @endif

                @if ($item['next_follow_up_at'])
                    <div class="mt-1 text-xs text-warning-600 dark:text-warning-400">
                        {{ __('resources.customer_communication.next_follow_up') }}:
                        <span class="tabular-nums">{{ $item['next_follow_up_at']->format('Y-m-d H:i') }}</span>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-sm text-gray-500 dark:text-[var(--dark-text-muted)]">
                {{ __('resources.customer_communication.empty') }}
            </div>
        @endforelse

        @if ($feed['total'] > $feed['items']->count())
            <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                {{ __('resources.customer_communication.more', ['count' => $feed['total'] - $feed['items']->count()]) }}
            </div>
        @endif
    </div>
@endif
