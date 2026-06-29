<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReturnVoucher;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReturnVoucherPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('return_vouchers.view');
    }

    public function view(User $user, ReturnVoucher $voucher): bool
    {
        return $user->can('return_vouchers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('return_vouchers.create');
    }

    public function update(User $user, ReturnVoucher $voucher): bool
    {
        return $user->can('return_vouchers.create') && ! $voucher->isPosted();
    }

    public function delete(User $user, ReturnVoucher $voucher): bool
    {
        return $user->can('return_vouchers.create') && ! $voucher->isPosted();
    }

    public function post(User $user, ReturnVoucher $voucher): bool
    {
        return $user->can('return_vouchers.post') && ! $voucher->isPosted();
    }

    public function restore(User $user, ReturnVoucher $voucher): bool
    {
        return $user->can('return_vouchers.create');
    }

    public function forceDelete(User $user, ReturnVoucher $voucher): bool
    {
        return $user->can('return_vouchers.create');
    }
}
