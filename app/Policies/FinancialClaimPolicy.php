<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinancialClaim;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FinancialClaimPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('financial_claims.view');
    }

    public function view(User $user, FinancialClaim $claim): bool
    {
        return $user->can('financial_claims.view');
    }

    public function create(User $user): bool
    {
        return $user->can('financial_claims.create');
    }

    public function update(User $user, FinancialClaim $claim): bool
    {
        return $user->can('financial_claims.create') && $claim->isDraft();
    }

    public function delete(User $user, FinancialClaim $claim): bool
    {
        return $user->can('financial_claims.create') && $claim->isDraft();
    }

    public function submit(User $user, FinancialClaim $claim): bool
    {
        return $user->can('financial_claims.submit') && $claim->isDraft();
    }

    public function collect(User $user, FinancialClaim $claim): bool
    {
        return $user->can('financial_claims.collect') && $claim->isSubmitted();
    }
}
