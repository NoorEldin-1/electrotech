<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a delivery voucher's header.
 *
 * Only while it is not yet active — `DeliveryVoucherPolicy::update` enforces
 * that, so an active voucher answers 403 rather than a business rule. Once
 * active the goods have left the store and the customer has been debited;
 * editing the paperwork behind a movement that already happened is how a
 * ledger stops matching reality.
 */
class UpdateDeliveryVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('delivery_voucher')) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'supply_order_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'voucher_date' => ['sometimes', 'date'],
            'plates_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'protection_degree' => ['sometimes', 'nullable', 'string', 'max:255'],
            'insulation_voltage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
