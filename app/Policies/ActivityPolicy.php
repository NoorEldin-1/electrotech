<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Activitylog\Contracts\Activity;

class ActivityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('activity_log.view');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('activity_log.view');
    }

    /**
     * Activity log is system-generated and append-only — never
     * created, updated, or deleted from the UI.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
