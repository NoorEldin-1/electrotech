<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('items.view');
    }

    public function view(User $user, Item $item): bool
    {
        return $user->can('items.view');
    }

    public function create(User $user): bool
    {
        return $user->can('items.create');
    }

    public function update(User $user, Item $item): bool
    {
        return $user->can('items.edit');
    }

    public function delete(User $user, Item $item): bool
    {
        return $user->can('items.delete');
    }

    public function restore(User $user, Item $item): bool
    {
        return $user->can('items.delete');
    }

    public function forceDelete(User $user, Item $item): bool
    {
        return $user->can('items.delete');
    }
}
