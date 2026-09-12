<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

class SetAlarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('setAlarm', $this->route('project')) ?? false;
    }

    public function rules(): array
    {
        return [
            // A reminder in the past would fire immediately and never be seen,
            // which reads to the user as the feature being broken.
            'alarm_at' => ['required', 'date', 'after:now'],
            'alarm_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
