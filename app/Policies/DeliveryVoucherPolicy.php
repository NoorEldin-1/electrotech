<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryVoucher;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DeliveryVoucherPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('delivery_vouchers.view');
    }

    public function view(User $user, DeliveryVoucher $voucher): bool
    {
        return $user->can('delivery_vouchers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('delivery_vouchers.create');
    }

    public function update(User $user, DeliveryVoucher $voucher): bool
    {
        return $user->can('delivery_vouchers.create') && ! $voucher->isActive();
    }

    public function delete(User $user, DeliveryVoucher $voucher): bool
    {
        return $user->can('delivery_vouchers.create') && ! $voucher->isActive();
    }

    public function approveTechnical(User $user, DeliveryVoucher $voucher): bool
    {
        return $user->can('delivery_vouchers.approve_technical')
            && ! $voucher->isActive()
            && ! $voucher->isTechnicalApproved();
    }

    public function approveFinancial(User $user, DeliveryVoucher $voucher): bool
    {
        return $user->can('delivery_vouchers.approve_financial')
            && ! $voucher->isActive()
            && ! $voucher->isFinancialApproved();
    }

    public function cancel(User $user, DeliveryVoucher $voucher): bool
    {
        return $user->can('delivery_vouchers.cancel') && ! $voucher->isActive();
    }
}
