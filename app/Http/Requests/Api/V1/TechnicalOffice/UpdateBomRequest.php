<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\TechnicalOffice;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bom')) ?? false;
    }

    /**
     * The subject (project_id / output_item_id) is not editable. Moving a BOM
     * from one operation to another after lines exist would silently re-point
     * whatever has already been reserved against it.
     */
    public function rules(): array
    {
        return [
            'version' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
