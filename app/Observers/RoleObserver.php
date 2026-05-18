<?php

declare(strict_types=1);

namespace App\Observers;

use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RoleObserver ensures that whenever a Role is modified,
 * the Spatie Permissions Redis cache is immediately invalidated.
 */
class RoleObserver
{
    /**
     * Handle the Role "saved" event.
     */
    public function saved(Role $role): void
    {
        $this->flushCache();
    }

    /**
     * Handle the Role "deleted" event.
     */
    public function deleted(Role $role): void
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
