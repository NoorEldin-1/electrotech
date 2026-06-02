<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Installation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InstallationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('installations.view');
    }

    public function view(User $user, Installation $installation): bool
    {
        return $user->can('installations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('installations.manage');
    }

    public function update(User $user, Installation $installation): bool
    {
        return $user->can('installations.manage');
    }

    public function delete(User $user, Installation $installation): bool
    {
        return $user->can('installations.manage');
    }
}
