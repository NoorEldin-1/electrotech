<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('work_orders.view');
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('work_orders.view');
    }

    public function create(User $user): bool
    {
        return $user->can('work_orders.create');
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('work_orders.edit');
    }

    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('work_orders.delete');
    }

    public function restore(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('work_orders.delete');
    }

    public function forceDelete(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('work_orders.delete');
    }
}
