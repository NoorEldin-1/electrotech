<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use App\Models\DeliveryMinute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise a delivery minute against a delivery voucher.
 *
 * The operation and the customer are INHERITED from the voucher rather than
 * sent: a minute that named a different customer from the delivery it records
 * would be a contradiction nobody could resolve afterwards.
 */
class StoreDeliveryMinuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DeliveryMinute::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'delivery_voucher_id' => [
                'required',
                'integer',
                Rule::exists('delivery_vouchers', 'id')->whereNull('deleted_at'),
            ],
            'content' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
