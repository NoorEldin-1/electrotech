<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        if ($role->name === 'Admin') {
            return false;
        }

        return $user->can('roles.manage');
    }

    public function restore(User $user, Role $role): bool
    {
        if ($role->name === 'Admin') {
            return false;
        }

        return $user->can('roles.manage');
    }

    public function forceDelete(User $user, Role $role): bool
    {
        if ($role->name === 'Admin') {
            return false;
        }

        return $user->can('roles.manage');
    }
}
