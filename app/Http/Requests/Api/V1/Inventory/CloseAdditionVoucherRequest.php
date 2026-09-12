<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CloseAdditionVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoice', $this->route('addition_voucher')) ?? false;
    }

    public function rules(): array
    {
        return [
            // Required and non-blank. Closing a receipt as never-to-be-invoiced
            // is a decision somebody has to be able to defend later, and an
            // empty reason makes the audit trail useless.
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
