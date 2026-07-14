<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\WorkOrderStatus;
use App\Models\DeliveryVoucher;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        // Low-stock count: items whose total available (on_hand - on_hold)
        // summed across ALL warehouses has fallen below their minimum_stock.
        // Aggregated per item because an item can now hold rows in several
        // warehouses. Cached aggressively; the InventoryService invalidates
        // it after every stock movement.
        $lowStockCount = Cache::remember(
            'dashboard:low_stock_count',
            now()->addMinutes(5),
            fn (): int => DB::table('items')
                ->leftJoin('inventories', 'inventories.item_id', '=', 'items.id')
                ->whereNull('items.deleted_at')
                ->groupBy('items.id', 'items.minimum_stock')
                ->havingRaw('COALESCE(SUM(inventories.on_hand_quantity - inventories.on_hold_quantity), 0) < items.minimum_stock')
                ->select('items.id')
                ->get()
                ->count(),
        );

        // Delivery vouchers awaiting one or both signatures before they can
        // release finished goods to the customer.
        $pendingDeliveries = Cache::remember(
            'dashboard:pending_deliveries',
            now()->addMinutes(5),
            fn (): int => DeliveryVoucher::query()
                ->whereIn('status', [DeliveryVoucherStatus::Draft, DeliveryVoucherStatus::PendingApproval])
                ->count(),
        );

        return [
            Stat::make(__('Active Projects'), (string) $activeProjects)
                ->description(__('Currently in progress'))
                ->descriptionIcon('heroicon-m-play')
                ->color('primary'),

            Stat::make(__('Pending Deliveries'), (string) $pendingDeliveries)
                ->description(__('Awaiting dual approval'))
                ->descriptionIcon('heroicon-m-truck')
                ->color($pendingDeliveries > 0 ? 'warning' : 'success'),

            Stat::make(__('Pending Purchase Orders'), (string) $pendingPos)
                ->description(__('Awaiting delivery/receipt'))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('warning'),

            Stat::make(__('Active Manufacturing Orders'), (string) $activeWos)
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
