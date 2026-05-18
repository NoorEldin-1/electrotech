<?php

declare(strict_types=1);

namespace App\Observers;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * PermissionObserver ensures that whenever a Permission is modified,
 * the Spatie Permissions Redis cache is immediately invalidated.
 */
class PermissionObserver
{
    /**
     * Handle the Permission "saved" event.
     */
    public function saved(Permission $permission): void
    {
        $this->flushCache();
    }

    /**
     * Handle the Permission "deleted" event.
     */
    public function deleted(Permission $permission): void
    {
        $this->flushCache();
    }

    /**
     * Flush the Spatie permission cache from Redis.
     */
    protected function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
