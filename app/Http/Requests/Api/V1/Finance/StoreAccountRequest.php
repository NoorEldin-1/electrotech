<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an account to the chart of accounts.
 *
 * `code` is unique and is what every report orders by, so it is required even
 * though nothing in the schema derives from it. `statement_section` is
 * optional but worth setting: an account without one still posts and still
 * appears in the trial balance, yet lands nowhere on the income statement or
 * the balance sheet.
 *
 * `opening_balance` is accepted here because it is genuinely opening data —
 * what the account was carrying when the platform took over the books. Every
 * movement after that comes from a posted journal entry.
 */
class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Account::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('accounts', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],

            'type' => ['required', Rule::enum(AccountType::class)],
            'nature' => ['nullable', Rule::enum(AccountDirection::class)],
            'statement_section' => ['nullable', Rule::enum(StatementSection::class)],

            'currency' => ['nullable', 'string', 'size:3'],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'contra_of_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'party_control' => ['nullable', 'string', 'max:50'],

            'opening_balance' => ['nullable', 'numeric'],
            'opening_balance_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
