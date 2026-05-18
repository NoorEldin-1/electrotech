<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Leverage Redis for expensive aggregation queries. These keys are maintained
        // via event-driven invalidation in Model Observers to guarantee consistency.
        
        $activeProjectsCount = Cache::rememberForever('dashboard_active_projects', function () {
            return Project::where('status', ProjectStatus::InProgress)->count();
        });

        $pendingPurchaseOrdersCount = Cache::rememberForever('dashboard_pending_pos', function () {
            return PurchaseOrder::where('status', PurchaseOrderStatus::Submitted)->count();
        });

        $activeWorkOrdersCount = Cache::rememberForever('dashboard_active_wos', function () {
            return WorkOrder::where('status', WorkOrderStatus::InProgress)->count();
        });

        return [
            Stat::make(__('Active Projects'), (string) $activeProjectsCount)
                ->description(__('Currently in progress'))
                ->descriptionIcon('heroicon-m-play')
                ->color('primary'),
                
            Stat::make(__('Pending Purchase Orders'), (string) $pendingPurchaseOrdersCount)
                ->description(__('Awaiting delivery/receipt'))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('warning'),
                
            Stat::make(__('Active Work Orders'), (string) $activeWorkOrdersCount)
                ->description(__('Currently in production'))
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color('info'),
        ];
    }
}
