<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replace the whole material plan (خامات أمر التصنيع) in one request.
 *
 * The list is REPLACED, never merged, for the reason recorded in
 * API_PROGRESS.md: a phone on a weak link cannot reliably sequence "add line
 * 4, delete line 2, edit line 3", and one dropped request in the middle leaves
 * the server holding a plan that never existed on either side. Sending the
 * finished table is one decision the client can retry safely, and the
 * `Idempotency-Key` makes the retry free.
 *
 * Quantity may be zero: keeping a line at zero is how a planner records "this
 * material was considered and deliberately excluded" without losing the row.
 */
class ReplaceWorkOrderMaterialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('work_order')) ?? false;
    }

    public function rules(): array
    {
        return [
            'materials' => ['present', 'array', 'max:500'],
            'materials.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'materials.*.quantity' => ['required', 'numeric', 'min:0'],
            'materials.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'materials.*.notes' => ['nullable', 'string', 'max:1000'],

            // Marks a line a human set by hand. It exists so that re-pulling
            // the standard recipe can leave deliberate adjustments alone
            // instead of silently overwriting them.
            'materials.*.is_manual' => ['nullable', 'boolean'],
        ];
    }
}
