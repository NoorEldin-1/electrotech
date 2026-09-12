<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a manufacturing order's plan.
 *
 * There is no `status` rule, and that is the point: the order moves through
 * `approve-order`, `start`, `submit-qa`, `approve-qa`, `finish-manufacturing`
 * and `complete`, each with its own permission and its own pre-conditions. A
 * state machine expressed as an editable column is a state machine that gets
 * bypassed.
 *
 * `produced_quantity` and `waste_quantity` are absent for the same reason —
 * they belong to `submit-qa`.
 */
class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('work_order')) ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'bom_id' => ['sometimes', 'nullable', 'integer', Rule::exists('boms', 'id')->whereNull('deleted_at')],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],

            'planned_quantity' => ['sometimes', 'numeric', 'min:0'],
            'estimated_cost' => ['sometimes', 'numeric', 'min:0'],
            'planned_start_date' => ['sometimes', 'nullable', 'date'],
            'planned_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:planned_start_date'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],

            'specs' => ['sometimes', 'array'],
            'specs.conductor_type' => ['nullable', 'string', 'max:255'],
            'specs.cross_section' => ['nullable', 'string', 'max:255'],
            'specs.cross_section_e' => ['nullable', 'string', 'max:255'],
            'specs.external_body' => ['nullable', 'string', 'max:255'],
            'specs.protection_degree' => ['nullable', 'string', 'max:255'],
            'specs.paint' => ['nullable', 'string', 'max:255'],
            'specs.model' => ['nullable', 'string', 'max:255'],
            'specs.ampere' => ['nullable', 'string', 'max:255'],
            'specs.poles_count' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
