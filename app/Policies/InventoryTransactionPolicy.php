<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('transactions.view');
    }

    public function view(User $user, InventoryTransaction $inventoryTransaction): bool
    {
        return $user->can('transactions.view');
    }

    /**
     * Inventory transactions are system-generated records.
     * Manual creation is not permitted.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, InventoryTransaction $inventoryTransaction): bool
    {
        return false;
    }

    public function delete(User $user, InventoryTransaction $inventoryTransaction): bool
    {
        return false;
    }
}
