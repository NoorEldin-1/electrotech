<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use App\Enums\ArrivalMethod;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * Note what is absent: `code`, `status`, `created_by`, `actual_cost`,
     * `manager_approved_*` and the `lost_*` fields. Each is owned by the
     * server or by a pipeline transition, and accepting any of them here would
     * let a client set a state that the transitions exist to guard.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'client_name' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],

            'consultant_name' => ['nullable', 'string', 'max:255'],
            'engineer_name' => ['nullable', 'string', 'max:255'],
            'project_location' => ['nullable', 'string', 'max:255'],
            'arrival_method' => ['nullable', Rule::enum(ArrivalMethod::class)],

            'electric_current' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'section_type' => ['nullable', 'string', 'max:100'],
            'poles_count' => ['nullable', 'integer', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],

            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
