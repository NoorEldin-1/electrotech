{{--
    Loading skeleton for the dashboard KPI row (E2E report §5.1).

    StatsOverview is lazy: the dashboard shell paints immediately and the five
    numbers arrive in a second Livewire round-trip. Livewire's DEFAULT
    placeholder for a lazy component is an empty `<div>`, so for the ~1–1.5s
    that round-trip takes the row was a band of nothing — which reads as
    "broken", not as "loading".

    The wrapper/grid/card classes are copied verbatim from Filament's own
    stats-overview-widget + stat views, and the inner bars are sized to the
    text they stand in for (label = text-sm, value = text-3xl, description =
    text-sm). Same geometry means the real data replaces this with zero layout
    shift. `md:grid-cols-3` matches StatsOverviewWidget::getColumns() for a
    five-stat row — and, unlike an invented breakpoint, is a class Filament
    already ships compiled.
--}}
<div class="fi-wi-stats-overview grid gap-y-4" aria-hidden="true">
    <div class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-3">
        @foreach (range(1, $count ?? 5) as $i)
            <div class="fi-wi-stats-overview-stat relative rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="grid gap-y-2">
                    {{-- label --}}
                    <div class="et-skeleton h-5 w-32 rounded"></div>
                    {{-- value --}}
                    <div class="et-skeleton h-9 w-16 rounded"></div>
                    {{-- description --}}
                    <div class="et-skeleton h-5 w-40 rounded"></div>
                </div>
            </div>
        @endforeach
    </div>
</div>
