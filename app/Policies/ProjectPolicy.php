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
}
