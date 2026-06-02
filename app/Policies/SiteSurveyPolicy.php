<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SiteSurveyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('site_surveys.view');
    }

    public function view(User $user, SiteSurvey $survey): bool
    {
        return $user->can('site_surveys.view');
    }

    public function create(User $user): bool
    {
        return $user->can('site_surveys.manage');
    }

    public function update(User $user, SiteSurvey $survey): bool
    {
        return $user->can('site_surveys.manage');
    }

    public function delete(User $user, SiteSurvey $survey): bool
    {
        return $user->can('site_surveys.manage');
    }
}
