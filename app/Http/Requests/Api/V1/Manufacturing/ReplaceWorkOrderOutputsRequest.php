<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replace the whole list of finished products (المنتجات التامة).
 *
 * `present` rather than `required` so an empty array is a legal way to clear
 * the list; `required` would make "this order produces nothing yet"
 * impossible to express and push a client into sending a dummy line.
 *
 * `produced_quantity` and `waste_quantity` are not accepted here. They are
 * reported at `submit-qa` by the people who made the product; letting a
 * planner write them would let an order claim output it never had.
 */
class ReplaceWorkOrderOutputsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('work_order')) ?? false;
    }

    public function rules(): array
    {
        return [
            'outputs' => ['present', 'array', 'max:100'],
            'outputs.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'outputs.*.planned_quantity' => ['required', 'numeric', 'min:0'],
            'outputs.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
