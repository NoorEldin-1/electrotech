<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MasterData;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * See StoreItemRequest for why authorize() is implemented on the request and
 * not left to the controller alone.
 */
class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Supplier::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'profit_tax_exempt' => ['sometimes', 'boolean'],
        ];
    }
}
