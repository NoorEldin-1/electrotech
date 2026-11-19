{{--
    The browser-rendered twin of operation-timeline.blade.php, for the
    Projects Timeline slide-over (opens with no request). Same markup and
    classes; keep the two in step.

    Expects an Alpine scope with `current` = one entry of
    ProjectsTimeline::timelinePayload() and `filled` (animates the bar).
    Must stay a single root element: it lives inside <template x-if>.
--}}
<div class="et-report et-timeline space-y-4">
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
            <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.current_stage') }}</div>
            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-[var(--dark-text)]" x-text="current.summary.stage"></div>
            <template x-if="current.summary.group">
                <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]" x-text="current.summary.group"></div>
            </template>
        </div>

        <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
            <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.progress') }}</div>
            <div class="mt-1 text-sm font-semibold tabular-nums text-gray-950 dark:text-[var(--dark-text)]" x-text="`${current.summary.progress}%`"></div>
            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-[var(--surface-3)]">
                <div
                    class="h-full rounded-full bg-primary-500 transition-[width] duration-500 ease-out motion-reduce:transition-none"
                    x-bind:style="`width: ${filled ? current.summary.progress : 0}%`"
                ></div>
            </div>
        </div>

        <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
            <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.expected_delivery') }}</div>
            <div class="mt-1 text-sm font-semibold tabular-nums text-gray-950 dark:text-[var(--dark-text)]" x-text="current.summary.expected"></div>
        </div>

        <div class="rounded-lg bg-gray-50 p-3 dark:bg-[var(--surface-2)]">
            <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.operation_timeline.summary.remaining') }}</div>
            <div class="mt-1 text-sm font-semibold tabular-nums">
                <span
                    x-bind:class="{
                        'text-success-600 dark:text-success-400': current.summary.remaining.color === 'success',
                        'text-danger-600 dark:text-danger-400': current.summary.remaining.color === 'danger',
                        'text-gray-950 dark:text-[var(--dark-text)]': current.summary.remaining.text && ! current.summary.remaining.color,
                        'text-gray-500 dark:text-[var(--dark-text-muted)]': ! current.summary.remaining.text,
                    }"
                    x-text="current.summary.remaining.text ?? @js(__('resources.common.no_data'))"
                ></span>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <template x-for="group in current.groups" x-bind:key="group.label">
            <div class="rounded-lg border border-gray-200 p-4 dark:border-[var(--border-hairline)]">
                <div class="mb-3 text-sm font-semibold text-gray-950 dark:text-[var(--dark-text)]" x-text="group.label"></div>

                <ol>
                    <template x-for="(stage, i) in group.stages" x-bind:key="stage.key">
                        <li class="flex gap-3">
                            {{-- The rail: filled when reached, dashed when skipped, hollow when still ahead. --}}
                            <div class="flex flex-col items-center">
                                <span
                                    class="mt-1 block size-3 shrink-0 rounded-full ring-2"
                                    x-bind:class="{
                                        'bg-primary-500 ring-primary-500/30': stage.state === 'reached',
                                        'border border-dashed border-gray-400 bg-transparent ring-transparent dark:border-[var(--dark-text-faint)]': stage.state === 'skipped',
                                        'bg-gray-200 ring-gray-200 dark:bg-[var(--surface-3)] dark:ring-[var(--border-hairline)]': stage.state === 'ahead',
                                    }"
                                ></span>
                                <template x-if="i < group.stages.length - 1">
                                    <span
                                        class="w-px flex-1"
                                        x-bind:class="stage.state === 'reached' ? 'bg-primary-500/40' : 'bg-gray-200 dark:bg-[var(--border-hairline)]'"
                                    ></span>
                                </template>
                            </div>

                            <div class="pb-3">
                                <div
                                    class="text-sm font-medium"
                                    x-bind:class="stage.state === 'reached' ? 'text-gray-950 dark:text-[var(--dark-text)]' : 'text-gray-400 dark:text-[var(--dark-text-faint)]'"
                                    x-text="stage.label"
                                ></div>
                                <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                                    <template x-for="(part, p) in stage.meta" x-bind:key="p">
                                        <span><template x-if="p > 0"><span class="mx-1">·</span></template><span x-bind:class="{ 'tabular-nums': p === 0 && stage.state === 'reached' }" x-text="part"></span></span>
                                    </template>
                                </div>
                            </div>
                        </li>
                    </template>
                </ol>
            </div>
        </template>
    </div>

    {{-- التواصل مع العميل: the customer-communication feed (not a stage). --}}
    <div class="rounded-lg border border-gray-200 p-4 dark:border-[var(--border-hairline)]">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div class="text-sm font-semibold text-gray-950 dark:text-[var(--dark-text)]">{{ __('resources.customer_communication.heading') }}</div>
            <div class="text-xs tabular-nums text-gray-500 dark:text-[var(--dark-text-muted)]" x-text="current.interactions.total"></div>
        </div>

        <template x-if="current.interactions.items.length === 0">
            <div class="text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">{{ __('resources.customer_communication.empty') }}</div>
        </template>

        <ul class="space-y-3">
            <template x-for="(item, i) in current.interactions.items" x-bind:key="i">
                <li class="text-sm">
                    <div class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-[var(--dark-text-muted)]">
                        <span class="tabular-nums" x-text="item.at"></span>
                        <span>·</span>
                        <span x-text="item.kind"></span>
                        <template x-if="item.recorded_by">
                            <span class="contents"><span>·</span><span x-text="item.recorded_by"></span></span>
                        </template>
                    </div>
                    <div class="font-medium text-gray-950 dark:text-[var(--dark-text)]" x-show="item.summary" x-text="item.summary"></div>
                    <div class="whitespace-pre-line text-gray-700 dark:text-[var(--dark-text-muted)]" x-show="item.notes" x-text="item.notes"></div>
                    <template x-if="item.next_follow_up_at">
                        <div class="text-xs text-warning-600 dark:text-warning-400">
                            {{ __('resources.customer_communication.next_follow_up') }}:
                            <span class="tabular-nums" x-text="item.next_follow_up_at"></span>
                        </div>
                    </template>
                </li>
            </template>
        </ul>
    </div>
</div>
