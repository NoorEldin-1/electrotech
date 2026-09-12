<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit an installation's notes and links.
 *
 * No `status`: the stage moves through `start` and `complete`, each of which
 * also stamps the timestamp that goes with it. A status column a client can
 * write is a status column that drifts from the timestamps beside it.
 */
class UpdateInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('installation')) ?? false;
    }

    public function rules(): array
    {
        return [
            'delivery_voucher_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('delivery_vouchers', 'id')->whereNull('deleted_at'),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
