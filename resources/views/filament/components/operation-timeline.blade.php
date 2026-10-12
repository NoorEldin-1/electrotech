{{--
    الخط الزمني للعملية (Presentation.pptx) — one rendering shared by the
    Projects Timeline page, the operation file and the operation cost file.

    Lives under views/filament so the Tailwind theme scans its classes.
    @include('filament.components.operation-timeline', ['timeline' => …, 'summary' => …])

    $timeline  Collection from OperationTimelineService::for()
    $summary   array from OperationTimelineService::summary(), optional
--}}
@php($summary ??= null)
@php($groups = $timeline->groupBy('group'))

{{-- et-report + et-timeline: dark mode comes from the unlayered rules in
     theme.css, not dark:*-[var()] utilities (those lose on source order). --}}
<div class="et-report et-timeline space-y-4">
    @if ($summary)
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
                <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.current_stage') }}</div>
                <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-[var(--dark-text)]">{{ $summary['current_stage']['label'] }}</div>
                @if ($summary['current_stage']['group_label'])
                    <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ $summary['current_stage']['group_label'] }}</div>
                @endif
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
                <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.progress') }}</div>
                <div class="mt-1 text-sm font-semibold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">{{ $summary['progress_percent'] }}%</div>
                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-[var(--surface-3)]">
                    <div class="h-full rounded-full bg-primary-500" style="width: {{ $summary['progress_percent'] }}%"></div>
                </div>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
                <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.expected_delivery') }}</div>
                <div class="mt-1 text-sm font-semibold tabular-nums text-gray-950 dark:text-[var(--dark-text)]">
                    {{ $summary['expected_delivery']?->format('Y-m-d') ?? __('resources.operation_timeline.summary.not_set') }}
                </div>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
                <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.remaining') }}</div>
                <div class="mt-1 text-sm font-semibold tabular-nums">
                    @if ($summary['delivered_at'])
                        <span class="text-success-600 dark:text-success-400">{{ __('resources.operation_timeline.summary.delivered_on', ['date' => $summary['delivered_at']->format('Y-m-d')]) }}</span>
                    @elseif ($summary['days_overdue'] !== null)
                        <span class="text-danger-600 dark:text-danger-400">{{ __('resources.operation_timeline.summary.days_overdue', ['days' => $summary['days_overdue']]) }}</span>
                    @elseif ($summary['days_remaining'] !== null)
                        <span class="text-gray-950 dark:text-[var(--dark-text)]">{{ __('resources.operation_timeline.summary.days_remaining', ['days' => $summary['days_remaining']]) }}</span>
                    @else
                        <span class="text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.common.no_data') }}</span>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach (\App\Services\OperationTimelineService::GROUPS as $group)
            <div class="rounded-lg border border-gray-200 p-4 dark:border-[var(--border-hairline)]">
                <div class="mb-3 text-sm font-semibold text-gray-950 dark:text-[var(--dark-text)]">
                    {{ __("resources.operation_timeline.groups.{$group}") }}
                </div>

                <ol>
                    @foreach ($groups->get($group, collect()) as $stage)
                        <li class="flex gap-3">
                            {{-- The rail: filled when reached, dashed when skipped, hollow when still ahead. --}}
                            <div class="flex flex-col items-center">
                                <span @class([
                                    'mt-1 block size-3 shrink-0 rounded-full ring-2',
                                    'bg-primary-500 ring-primary-500/30' => $stage['reached'],
                                    'border border-dashed border-gray-400 bg-transparent ring-transparent dark:border-[var(--dark-text-faint)]' => $stage['skipped'],
                                    'bg-gray-200 ring-gray-200 dark:bg-[var(--surface-3)] dark:ring-[var(--border-hairline)]' => ! $stage['reached'] && ! $stage['skipped'],
                                ])></span>
                                @unless ($loop->last)
                                    <span @class([
                                        'w-px flex-1',
                                        'bg-primary-500/40' => $stage['reached'],
                                        'bg-gray-200 dark:bg-[var(--border-hairline)]' => ! $stage['reached'],
                                    ])></span>
                                @endunless
                            </div>

                            <div class="pb-3">
                                <div @class([
                                    'text-sm font-medium',
                                    'text-gray-950 dark:text-[var(--dark-text)]' => $stage['reached'],
                                    'text-gray-400 dark:text-[var(--dark-text-faint)]' => ! $stage['reached'],
                                ])>
                                    {{ $stage['label'] }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                                    @if ($stage['reached'])
                                        <span class="tabular-nums">{{ $stage['at']->format('Y-m-d') }}</span>
                                        @if ($stage['days_from_start'])
                                            <span class="mx-1">·</span>{{ __('resources.operation_timeline.days_from_start', ['days' => $stage['days_from_start']]) }}
                                        @endif
                                        @if ($stage['detail'])
                                            <span class="mx-1">·</span>{{ $stage['detail'] }}
                                        @endif
                                    @elseif ($stage['skipped'])
                                        {{ __('resources.operation_timeline.skipped') }}
                                    @else
                                        {{ __('resources.operation_timeline.not_reached') }}
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endforeach
    </div>
</div>
