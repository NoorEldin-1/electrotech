<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerInteraction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerInteractionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('customer_interactions.view');
    }

    public function view(User $user, CustomerInteraction $interaction): bool
    {
        return $user->can('customer_interactions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customer_interactions.manage');
    }

    public function update(User $user, CustomerInteraction $interaction): bool
    {
        return $user->can('customer_interactions.manage');
    }

    public function delete(User $user, CustomerInteraction $interaction): bool
    {
        return $user->can('customer_interactions.manage');
    }
}
