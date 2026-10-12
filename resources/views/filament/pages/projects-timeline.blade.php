<x-filament-panels::page>
    {{ $this->table }}

    {{--
        The operation timeline opens in the browser, with no request: the rows'
        timelines ride along with the table (timelinePayload(), built from what
        the columns already computed) and are re-rendered with it on every
        search, filter, sort or page change. Read at click time, so a row always
        opens its own, current data.
    --}}
    <div
        x-data="{
            list: [],
            index: -1,
            current: null,
            filled: false,
            refreshing: false,
            read() {
                try {
                    return JSON.parse(this.$refs.payload.dataset.payload)
                } catch (e) {
                    return []
                }
            },
            select(i) {
                this.index = i
                this.current = this.list[i]
                // Let the progress bar grow from 0 each time a timeline shows.
                this.filled = false
                requestAnimationFrame(() => requestAnimationFrame(() => (this.filled = true)))
            },
            show(id) {
                this.list = this.read()
                const i = this.list.findIndex((t) => t.id === id)
                if (i === -1) return
                this.select(i)
                this.$dispatch('open-modal', { id: 'et-operation-timeline' })
            },
            step(delta) {
                const i = this.index + delta
                if (i >= 0 && i < this.list.length) this.select(i)
            },
            async refresh() {
                if (this.refreshing) return
                this.refreshing = true
                try {
                    await this.$wire.$refresh()
                    this.list = this.read()
                    const i = this.list.findIndex((t) => t.id === this.current?.id)
                    if (i !== -1) this.select(i)
                } finally {
                    this.refreshing = false
                }
            },
        }"
        x-on:et-open-timeline.window="show($event.detail.id)"
    >
        <div x-ref="payload" data-payload="{{ json_encode($this->timelinePayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" hidden></div>

        <x-filament::modal id="et-operation-timeline" slide-over width="7xl">
            <x-slot name="heading">
                <span x-text="current?.heading"></span>
            </x-slot>

            <template x-if="current">
                @include('filament.components.operation-timeline-client')
            </template>

            <x-slot name="footer">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'et-operation-timeline' })">
                        {{ __('resources.common.close') }}
                    </x-filament::button>

                    <template x-if="current?.url">
                        <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-top-right-on-square" x-bind:href="current.url">
                            {{ __('resources.projects_timeline.actions.open_operation') }}
                        </x-filament::button>
                    </template>

                    <div class="ms-auto flex items-center gap-1">
                        <x-filament::icon-button
                            icon="heroicon-o-arrow-path"
                            color="gray"
                            :label="__('resources.projects_timeline.actions.refresh')"
                            :tooltip="__('resources.projects_timeline.actions.refresh')"
                            x-on:click="refresh()"
                            x-bind:class="{ 'animate-spin': refreshing }"
                            x-bind:disabled="refreshing"
                        />
                        {{-- RTL: «السابق» points right, «التالي» points left. --}}
                        <x-filament::icon-button
                            icon="heroicon-o-chevron-right"
                            color="gray"
                            class="ltr:rotate-180"
                            :label="__('resources.projects_timeline.actions.previous')"
                            :tooltip="__('resources.projects_timeline.actions.previous')"
                            x-on:click="step(-1)"
                            x-bind:disabled="index <= 0"
                        />
                        <span class="min-w-12 text-center text-xs tabular-nums text-gray-500 dark:text-[var(--dark-text-muted)]" x-text="list.length ? `${index + 1} / ${list.length}` : ''"></span>
                        <x-filament::icon-button
                            icon="heroicon-o-chevron-left"
                            color="gray"
                            class="ltr:rotate-180"
                            :label="__('resources.projects_timeline.actions.next')"
                            :tooltip="__('resources.projects_timeline.actions.next')"
                            x-on:click="step(1)"
                            x-bind:disabled="index >= list.length - 1"
                        />
                    </div>
                </div>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
