<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Commit part of a credit facility to an operation.
 *
 * The amount is checked against what the facility has left, in the service, so
 * a facility cannot be promised twice. Read `utilization.available` on the
 * facility first and show it — it is cheaper to stop the user before they type
 * a number than to refuse them after.
 */
class AllocateCreditFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('credit_facility')) ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
