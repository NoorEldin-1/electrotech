<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\TechnicalOffice;

use App\Models\Bom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Bom::class) ?? false;
    }

    /**
     * `status`, `prepared_by`, `approved_by` and `approved_at` are absent: the
     * state machine and the signatures belong to BomService. A client that
     * could post `status: approved` would bypass every guard the approve
     * endpoint exists to apply.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'output_item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'version' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Exactly one subject. A BOM claiming to be both a project BOM and a
     * standard recipe would be superseded against the wrong sibling on
     * approval, silently retiring a recipe nobody meant to touch.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasProject = $this->filled('project_id');
            $hasOutputItem = $this->filled('output_item_id');

            if ($hasProject === $hasOutputItem) {
                $validator->errors()->add(
                    'project_id',
                    'Give exactly one of project_id (a BOM for an operation) or output_item_id (a standard recipe for a product).',
                );
            }
        });
    }
}
