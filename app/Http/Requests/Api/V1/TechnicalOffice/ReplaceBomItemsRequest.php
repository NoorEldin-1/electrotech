<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\TechnicalOffice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceBomItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bom')) ?? false;
    }

    public function rules(): array
    {
        return [
            // `present`, not `required`: an empty array means "clear the
            // lines", and `required` rejects `[]`.
            'items' => ['present', 'array', 'max:500'],

            'items.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],

            // Greater than zero, not just non-negative. A zero-quantity BOM
            // line reserves nothing and issues nothing, so it is a data-entry
            // slip that would otherwise sit in the document looking real.
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],

            'items.*.waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
