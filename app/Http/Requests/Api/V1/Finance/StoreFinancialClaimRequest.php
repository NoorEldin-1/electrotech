<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use App\Models\FinancialClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise a financial claim against a customer.
 *
 * Always starts as a Draft. `status`, `submitted_at` and `collected_at` belong
 * to the two transition endpoints — a claim that could be created as collected
 * would be a receivable nobody ever chased.
 */
class StoreFinancialClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FinancialClaim::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'claim_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
