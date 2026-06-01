<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IssueVoucher;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class IssueVoucherPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('issue_vouchers.view');
    }

    public function view(User $user, IssueVoucher $voucher): bool
    {
        return $user->can('issue_vouchers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('issue_vouchers.create');
    }

    public function update(User $user, IssueVoucher $voucher): bool
    {
        return $user->can('issue_vouchers.create') && ! $voucher->isPosted();
    }

    public function delete(User $user, IssueVoucher $voucher): bool
    {
        return $user->can('issue_vouchers.create') && ! $voucher->isPosted();
    }

    public function post(User $user, IssueVoucher $voucher): bool
    {
        return $user->can('issue_vouchers.post') && ! $voucher->isPosted();
    }

    public function restore(User $user, IssueVoucher $voucher): bool
    {
        return $user->can('issue_vouchers.create');
    }

    public function forceDelete(User $user, IssueVoucher $voucher): bool
    {
        return $user->can('issue_vouchers.create');
    }
}
