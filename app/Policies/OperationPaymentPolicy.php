<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OperationPayment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class OperationPaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('operation_payments.view');
    }

    public function view(User $user, OperationPayment $payment): bool
    {
        return $user->can('operation_payments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('operation_payments.record');
    }

    public function update(User $user, OperationPayment $payment): bool
    {
        // A payment that already posted a GL entry is locked.
        return $user->can('operation_payments.record') && $payment->journal_entry_id === null;
    }

    public function delete(User $user, OperationPayment $payment): bool
    {
        return $user->can('operation_payments.record') && $payment->journal_entry_id === null;
    }
}
