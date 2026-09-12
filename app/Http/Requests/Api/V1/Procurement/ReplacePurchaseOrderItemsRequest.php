<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Procurement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplacePurchaseOrderItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('purchase_order')) ?? false;
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:500'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],

            // Zero is allowed: a free-of-charge line (a replacement part, a
            // sample) is a real thing on a purchase order, and refusing it
            // would push the user to invent a nominal price that then lands in
            // the ledger.
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
