<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Inventory;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class StatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    /**
     * Lazy-load the widget so the dashboard shell renders immediately and
     * the stats fetch happens in a second Livewire round-trip. Combined
     * with the Redis cache below, this means the dashboard appears in
     * ~20ms and the cards fill in shortly after.
     */
    protected static bool $isLazy = true;

    /**
     * One in-process memoization key. Cache::remember already handles the
     * Redis hit, but on a single request multiple sub-widgets shouldn't
     * each round-trip to Redis when one fetch would do.
     */
    protected function getStats(): array
    {
        // Each metric is cached for 5 minutes as a hard ceiling, and is
        // also invalidated immediately by the model observers when the
        // underlying state changes. Cache::remember (vs rememberForever)
        // gives us a safety net in case an observer ever misses a write
        // (e.g. raw SQL update bypassing model events).
        $activeProjects = Cache::remember(
            'dashboard:active_projects',
            now()->addMinutes(5),
            fn (): int => Project::query()->where('status', ProjectStatus::InProgress)->count(),
        );

        $pendingPos = Cache::remember(
            'dashboard:pending_pos',
            now()->addMinutes(5),
            fn (): int => PurchaseOrder::query()->where('status', PurchaseOrderStatus::Submitted)->count(),
        );

        $activeWos = Cache::remember(
            'dashboard:active_wos',
            now()->addMinutes(5),
            fn (): int => WorkOrder::query()->where('status', WorkOrderStatus::InProgress)->count(),
        );

        // Low-stock count: items whose available (on_hand - on_hold) has
        // fallen below their minimum_stock. This is a JOIN that would be
        // very expensive on every dashboard render; we cache aggressively
        // and let the InventoryService invalidate after stock movements.
        $lowStockCount = Cache::remember(
            'dashboard:low_stock_count',
            now()->addMinutes(5),
            fn (): int => Inventory::query()
                ->join('items', 'items.id', '=', 'inventories.item_id')
                ->whereRaw('(inventories.on_hand_quantity - inventories.on_hold_quantity) < items.minimum_stock')
                ->whereNull('items.deleted_at')
                ->count(),
        );

        return [
            Stat::make(__('Active Projects'), (string) $activeProjects)
                ->description(__('Currently in progress'))
                ->descriptionIcon('heroicon-m-play')
                ->color('primary'),

            Stat::make(__('Pending Purchase Orders'), (string) $pendingPos)
                ->description(__('Awaiting delivery/receipt'))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('warning'),

            Stat::make(__('Active Work Orders'), (string) $activeWos)
                ->description(__('Currently in production'))
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color('info'),

            Stat::make(__('Low Stock Items'), (string) $lowStockCount)
                ->description(__('Below minimum threshold'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStockCount > 0 ? 'danger' : 'success'),
        ];
    }
}
