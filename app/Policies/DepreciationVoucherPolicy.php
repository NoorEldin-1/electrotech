<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DepreciationVoucher;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DepreciationVoucherPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('depreciation_vouchers.view');
    }

    public function view(User $user, DepreciationVoucher $voucher): bool
    {
        return $user->can('depreciation_vouchers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('depreciation_vouchers.create');
    }

    public function update(User $user, DepreciationVoucher $voucher): bool
    {
        return $user->can('depreciation_vouchers.create') && ! $voucher->isPosted();
    }

    public function delete(User $user, DepreciationVoucher $voucher): bool
    {
        return $user->can('depreciation_vouchers.create') && ! $voucher->isPosted();
    }

    public function post(User $user, DepreciationVoucher $voucher): bool
    {
        return $user->can('depreciation_vouchers.post') && ! $voucher->isPosted();
    }

    public function restore(User $user, DepreciationVoucher $voucher): bool
    {
        return $user->can('depreciation_vouchers.create');
    }

    public function forceDelete(User $user, DepreciationVoucher $voucher): bool
    {
        return $user->can('depreciation_vouchers.create');
    }
}
