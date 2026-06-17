<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Project;
use App\Services\SalesAlertService;
use Illuminate\Support\Facades\Cache;

class ProjectObserver
{
    /**
     * Handle the Project "created" event.
     *
     * A brand-new operation is created *directly* as a Tender (see
     * Project::booted + the disabled, Tender-defaulted status field on the
     * intake form) — it never passes through SalesPipelineService::moveToTender,
     * which is where status transitions reconcile the bell. So raise its
     * "missing offer" / "missing SMB" alert here, the moment it is created.
     */
    public function created(Project $project): void
    {
        app(SalesAlertService::class)->reconcileOperationAlerts();
    }

    /**
     * Handle the Project "saved" event.
     */
    public function saved(Project $project): void
    {
        $this->invalidateDashboardCounts();
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        $this->invalidateDashboardCounts();
    }

    private function invalidateDashboardCounts(): void
    {
        Cache::forget('dashboard:active_projects');
        Cache::forget('dashboard:tender_count');
        Cache::forget('dashboard:inhand_count');
        Cache::forget('dashboard:lost_count');
    }
}
