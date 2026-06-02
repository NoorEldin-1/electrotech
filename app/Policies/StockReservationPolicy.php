<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockReservationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('operations.reserve');
    }

    public function view(User $user, StockReservation $reservation): bool
    {
        return $user->can('operations.reserve');
    }

    public function create(User $user): bool
    {
        return $user->can('operations.reserve');
    }

    public function update(User $user, StockReservation $reservation): bool
    {
        return $user->can('operations.reserve');
    }

    public function delete(User $user, StockReservation $reservation): bool
    {
        return $user->can('operations.reserve');
    }

    public function restore(User $user, StockReservation $reservation): bool
    {
        return $user->can('operations.reserve');
    }

    public function forceDelete(User $user, StockReservation $reservation): bool
    {
        return $user->can('operations.reserve');
    }
}
