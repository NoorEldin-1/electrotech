<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryMinute;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DeliveryMinutePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('delivery_minutes.view');
    }

    public function view(User $user, DeliveryMinute $minute): bool
    {
        return $user->can('delivery_minutes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('delivery_minutes.create');
    }

    public function update(User $user, DeliveryMinute $minute): bool
    {
        return $user->can('delivery_minutes.create') && ! $minute->isDistributed();
    }

    public function delete(User $user, DeliveryMinute $minute): bool
    {
        return $user->can('delivery_minutes.create') && ! $minute->isDistributed();
    }

    public function distribute(User $user, DeliveryMinute $minute): bool
    {
        return $user->can('delivery_minutes.distribute');
    }
}
