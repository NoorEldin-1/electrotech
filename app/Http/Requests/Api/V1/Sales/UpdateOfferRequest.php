<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('offer')) ?? false;
    }

    public function rules(): array
    {
        return [
            'quotation_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'vat_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'show_vat' => ['sometimes', 'boolean'],
            'installation_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'show_installation' => ['sometimes', 'boolean'],
            'header_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'general_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
