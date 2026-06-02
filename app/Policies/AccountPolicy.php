<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('accounts.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can('accounts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.create');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounts.edit');
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounts.delete');
    }

    public function restore(User $user, Account $account): bool
    {
        return $user->can('accounts.delete');
    }

    public function forceDelete(User $user, Account $account): bool
    {
        return $user->can('accounts.delete');
    }
}
