<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a draft claim.
 *
 * Drafts only — the policy gates on `isDraft()`, so a submitted claim answers
 * **403**. Once a claim has gone to the customer, changing its amount quietly
 * would leave the file and the customer's copy saying different things.
 */
class UpdateFinancialClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('financial_claim')) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'claim_date' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
