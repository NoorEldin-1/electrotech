<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.view');
    }

    public function create(User $user): bool
    {
        return $user->can('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('projects.edit');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.delete');
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->can('projects.delete');
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return $user->can('projects.delete');
    }

    public function moveToTender(User $user, Project $project): bool
    {
        return $user->can('projects.move_to_tender');
    }

    public function moveToInHand(User $user, Project $project): bool
    {
        return $user->can('projects.move_to_inhand');
    }

    public function moveToActive(User $user, Project $project): bool
    {
        return $user->can('projects.move_to_active');
    }

    public function managerApprove(User $user, Project $project): bool
    {
        return $user->can('projects.manager_approve');
    }

    public function cancelToLost(User $user, Project $project): bool
    {
        return $user->can('projects.cancel_to_lost');
    }

    public function setAlarm(User $user, Project $project): bool
    {
        return $user->can('projects.set_alarm');
    }
}
