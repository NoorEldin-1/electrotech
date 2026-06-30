<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QualitySheet;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class QualitySheetPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('quality_sheets.view');
    }

    public function view(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quality_sheets.create');
    }

    public function update(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.create') && ! $sheet->isApproved();
    }

    /**
     * The QA department fills the test results and signs.
     */
    public function fill(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.fill') && ! $sheet->isApproved();
    }

    /**
     * The factory manager gives final approval — only after QA has filled it.
     */
    public function approve(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.approve') && $sheet->isQaFilled();
    }

    public function print(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.print');
    }

    public function delete(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.create') && ! $sheet->isApproved();
    }

    public function restore(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.create');
    }

    public function forceDelete(User $user, QualitySheet $sheet): bool
    {
        return $user->can('quality_sheets.create');
    }
}
