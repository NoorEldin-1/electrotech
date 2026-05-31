<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

class ProjectObserver
{
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
