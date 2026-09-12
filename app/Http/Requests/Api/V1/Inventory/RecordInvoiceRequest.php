<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class RecordInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoice', $this->route('addition_voucher')) ?? false;
    }

    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'invoice_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
