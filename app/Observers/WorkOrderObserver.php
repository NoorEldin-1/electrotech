<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;

class WorkOrderObserver
{
    /**
     * Handle the WorkOrder "saved" event.
     */
    public function saved(WorkOrder $workOrder): void
    {
        Cache::forget('dashboard_active_wos');
    }

    /**
     * Handle the WorkOrder "deleted" event.
     */
    public function deleted(WorkOrder $workOrder): void
    {
        Cache::forget('dashboard_active_wos');
    }
}
