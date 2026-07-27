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

    /**
     * Slide 11: recording the supplier invoice — or closing the receipt
     * without one — is a financial decision, not a warehouse one. The
     * storekeeper receives the goods; finance decides the invoicing state.
     */
    public function invoice(User $user, AdditionVoucher $voucher): bool
    {
        return $user->can('addition_vouchers.invoice');
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
