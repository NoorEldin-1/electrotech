<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Models\OperationPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record cash moving in or out against an operation.
 *
 * When the platform is configured to post payments automatically and the
 * accounts resolve, this also writes and posts a balanced journal entry. That
 * is why `account_id` and `counter_account_id` are worth sending: without
 * them there is nothing to post, and the payment stays a record with no
 * ledger entry behind it.
 *
 * `journal_entry_id` is not accepted. A client that could name the entry could
 * point a payment at somebody else's.
 */
class StoreOperationPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OperationPayment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'financial_claim_id' => ['nullable', 'integer', Rule::exists('financial_claims', 'id')->whereNull('deleted_at')],

            'direction' => ['required', Rule::enum(PaymentDirection::class)],
            'method' => ['required', Rule::enum(PaymentMethod::class)],

            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'counter_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],

            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
