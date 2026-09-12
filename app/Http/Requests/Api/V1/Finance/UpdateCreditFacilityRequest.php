<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use App\Enums\FacilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCreditFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('credit_facility')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'limit_amount' => ['sometimes', 'numeric', 'gt:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::enum(FacilityStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
