<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('account')) ?? false;
    }

    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('accounts', 'code')->ignore($account)->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],

            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'nature' => ['sometimes', 'nullable', Rule::enum(AccountDirection::class)],
            'statement_section' => ['sometimes', 'nullable', Rule::enum(StatementSection::class)],

            'currency' => ['sometimes', 'string', 'size:3'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'contra_of_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'party_control' => ['sometimes', 'nullable', 'string', 'max:50'],

            'opening_balance' => ['sometimes', 'numeric'],
            'opening_balance_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
