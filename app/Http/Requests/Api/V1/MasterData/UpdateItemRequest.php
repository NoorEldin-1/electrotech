<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MasterData;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('item')) ?? false;
    }

    /**
     * Every rule is `sometimes`: a PATCH sends only what changed, and marking
     * a field `required` would force the client to echo back values it never
     * touched — which is how a mobile form overwrites a field someone else
     * edited in the panel a second earlier.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('items', 'sku')->ignore($this->route('item')?->id),
            ],
            'type' => ['sometimes', 'required', Rule::enum(ItemType::class)],
            'unit' => ['sometimes', 'required', Rule::enum(UnitOfMeasure::class)],
            'unit_cost' => ['sometimes', 'required', 'numeric', 'min:0'],
            'minimum_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
