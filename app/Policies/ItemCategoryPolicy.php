<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Categories are part of the item catalog, so they ride on the existing
 * `items.*` permissions instead of introducing a new permission set.
 */
class ItemCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('items.view');
    }

    public function view(User $user, ItemCategory $category): bool
    {
        return $user->can('items.view');
    }

    public function create(User $user): bool
    {
        return $user->can('items.create');
    }

    public function update(User $user, ItemCategory $category): bool
    {
        return $user->can('items.edit');
    }

    /**
     * A category that still groups items cannot be deleted — the items would
     * silently lose their grouping.
     */
    public function delete(User $user, ItemCategory $category): bool
    {
        return $user->can('items.delete') && ! $category->items()->withTrashed()->exists();
    }
}
