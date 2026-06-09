<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProjectOffer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectOfferPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('project_offers.view');
    }

    public function view(User $user, ProjectOffer $offer): bool
    {
        return $user->can('project_offers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('project_offers.create');
    }

    public function update(User $user, ProjectOffer $offer): bool
    {
        return $user->can('project_offers.edit');
    }

    public function delete(User $user, ProjectOffer $offer): bool
    {
        return $user->can('project_offers.delete');
    }

    public function print(User $user, ProjectOffer $offer): bool
    {
        return $user->can('project_offers.print');
    }

    public function restore(User $user, ProjectOffer $offer): bool
    {
        return $user->can('project_offers.delete');
    }

    public function forceDelete(User $user, ProjectOffer $offer): bool
    {
        return $user->can('project_offers.delete');
    }
}
