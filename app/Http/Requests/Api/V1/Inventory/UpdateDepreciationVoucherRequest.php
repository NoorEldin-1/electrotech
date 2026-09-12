<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Inventory;

use App\Enums\LossType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepreciationVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('depreciation_voucher')) ?? false;
    }

    public function rules(): array
    {
        return [
            // The loss type is not cosmetic: abnormal loss is reversed off the
            // operation's cost, natural loss stays loaded on it. Getting it
            // wrong misstates the job's cost, so it is an enum and never free
            // text.
            'loss_type' => ['sometimes', 'required', Rule::enum(LossType::class)],
            'voucher_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // `present`: an empty array clears the lines. Lines left at zero
            // quantity are ignored at posting, which is how the pre-filled
            // draft is meant to be used — set the ones that were lost, leave
            // the rest.
            'lines' => ['sometimes', 'present', 'array', 'max:500'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
