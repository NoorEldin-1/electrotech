<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountEntry;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('supplier_statements.view') || $user->can('customer_statements.view');
    }

    public function view(User $user, AccountEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AccountEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, AccountEntry $entry): bool
    {
        return false;
    }
}
