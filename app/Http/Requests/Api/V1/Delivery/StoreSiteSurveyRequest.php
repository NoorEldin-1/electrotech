<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use App\Models\SiteSurvey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a site survey.
 *
 * `measurements` is free text by design — see SiteSurveyResource. The
 * surveyor is the authenticated user unless one is named explicitly, so the
 * common case needs no field at all.
 */
class StoreSiteSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SiteSurvey::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'survey_date' => ['nullable', 'date'],
            'measurements' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'surveyed_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
