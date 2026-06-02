<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CreditFacility;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CreditFacilityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('credit_facilities.view');
    }

    public function view(User $user, CreditFacility $facility): bool
    {
        return $user->can('credit_facilities.view');
    }

    public function create(User $user): bool
    {
        return $user->can('credit_facilities.manage');
    }

    public function update(User $user, CreditFacility $facility): bool
    {
        return $user->can('credit_facilities.manage');
    }

    public function delete(User $user, CreditFacility $facility): bool
    {
        return $user->can('credit_facilities.manage');
    }
}
