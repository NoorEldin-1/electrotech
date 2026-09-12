<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use App\Enums\ArrivalMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /**
     * `status` is absent by design — see PipelineController. `acceptance_email_at`
     * *is* editable here, because it records a fact (the consultant's email
     * arrived on this date) rather than performing a transition; the transition
     * that depends on it is still gated by its own permission.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'client_name' => ['sometimes', 'required', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],

            'consultant_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'engineer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'arrival_method' => ['sometimes', 'nullable', Rule::enum(ArrivalMethod::class)],

            'electric_current' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'section_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'poles_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'estimated_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'acceptance_email_at' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
