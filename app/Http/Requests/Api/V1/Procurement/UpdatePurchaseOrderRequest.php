<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Procurement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('purchase_order')) ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'apply_profit_tax' => ['sometimes', 'boolean'],
            'expected_delivery_date' => ['sometimes', 'nullable', 'date'],
            'supplier_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
