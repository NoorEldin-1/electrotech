<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * QA signs off the readings on a quality sheet.
 *
 * The readings themselves are written with
 * `PUT /quality-sheets/{id}/lines`; this endpoint is the signature. Splitting
 * the two keeps a half-typed sheet from being mistaken for a signed one — an
 * inspector can save readings all afternoon and sign once.
 */
class FillQualitySheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('fill', $this->route('quality_sheet')) ?? false;
    }

    public function rules(): array
    {
        return [
            'qa_inspector_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
