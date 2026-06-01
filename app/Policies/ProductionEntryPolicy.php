<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductionEntry;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductionEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('production_entries.view');
    }

    public function view(User $user, ProductionEntry $entry): bool
    {
        return $user->can('production_entries.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProductionEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, ProductionEntry $entry): bool
    {
        return false;
    }
}
