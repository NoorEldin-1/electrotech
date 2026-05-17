<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bom;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BomPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('boms.view');
    }

    public function view(User $user, Bom $bom): bool
    {
        return $user->can('boms.view');
    }

    public function create(User $user): bool
    {
        return $user->can('boms.create');
    }

    public function update(User $user, Bom $bom): bool
    {
        return $user->can('boms.edit');
    }

    public function delete(User $user, Bom $bom): bool
    {
        return $user->can('boms.delete');
    }

    public function restore(User $user, Bom $bom): bool
    {
        return $user->can('boms.delete');
    }

    public function forceDelete(User $user, Bom $bom): bool
    {
        return $user->can('boms.delete');
    }
}
