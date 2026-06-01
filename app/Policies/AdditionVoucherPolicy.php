<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdditionVoucher;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AdditionVoucherPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('addition_vouchers.view');
    }

    public function view(User $user, AdditionVoucher $voucher): bool
    {
        return $user->can('addition_vouchers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('addition_vouchers.create');
    }

    public function update(User $user, AdditionVoucher $voucher): bool
    {
        // Only draft vouchers are editable; posted ones are immutable.
        return $user->can('addition_vouchers.create') && ! $voucher->isPosted();
    }

    public function delete(User $user, AdditionVoucher $voucher): bool
    {
        return $user->can('addition_vouchers.create') && ! $voucher->isPosted();
    }

    public function post(User $user, AdditionVoucher $voucher): bool
    {
        return $user->can('addition_vouchers.post') && ! $voucher->isPosted();
    }

    public function restore(User $user, AdditionVoucher $voucher): bool
    {
        return $user->can('addition_vouchers.create');
    }

    public function forceDelete(User $user, AdditionVoucher $voucher): bool
    {
        return $user->can('addition_vouchers.create');
    }
}
