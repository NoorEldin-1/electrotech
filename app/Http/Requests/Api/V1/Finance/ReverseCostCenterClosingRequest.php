<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Undo a cost-centre closing.
 *
 * A posted journal entry is immutable, so this writes a REVERSING entry (Dr
 * inventory / Cr COGS) plus a negative closing row. The unclosed balance comes
 * back on its own and the audit trail stays whole — nothing is deleted, and
 * both rows remain visible with their link.
 */
class ReverseCostCenterClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operations.close_cost_center') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
