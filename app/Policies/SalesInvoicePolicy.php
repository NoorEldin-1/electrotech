<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalesInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('sales_invoices.view');
    }

    public function view(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales_invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales_invoices.create');
    }

    public function update(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales_invoices.edit');
    }

    public function delete(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales_invoices.delete');
    }

    public function restore(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales_invoices.delete');
    }
}
