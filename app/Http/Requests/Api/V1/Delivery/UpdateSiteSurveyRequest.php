<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('site_survey')) ?? false;
    }

    public function rules(): array
    {
        return [
            'survey_date' => ['sometimes', 'date'],
            'measurements' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'surveyed_by' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
