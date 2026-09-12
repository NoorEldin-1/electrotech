<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use App\Models\Installation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open an installation for an operation.
 *
 * It always starts Pending; `started_at` and `completed_at` are stamped by the
 * two transition endpoints and are not accepted here. A client that could
 * write them could record an installation as finished without anyone having
 * gone to site.
 */
class StoreInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Installation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'delivery_voucher_id' => [
                'nullable',
                'integer',
                Rule::exists('delivery_vouchers', 'id')->whereNull('deleted_at'),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
