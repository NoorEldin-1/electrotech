<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replace the whole list of goods on a delivery voucher.
 *
 * Quantity must be positive here, unlike a return voucher: a delivery line of
 * zero would be a promise to hand over nothing, and it would still be printed
 * on the paperwork the customer signs.
 */
class ReplaceDeliveryVoucherLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('delivery_voucher')) ?? false;
    }

    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
