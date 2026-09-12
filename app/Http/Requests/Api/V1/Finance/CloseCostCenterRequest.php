<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Close an operation's cost centre by hand.
 *
 * The automatic path fires when a delivery activates and is deliberately
 * silent; this is the manual one, and every refusal it makes is explicit so
 * the user learns why nothing was posted — no active delivery, nothing left to
 * close, or the chart of accounts missing the COGS or inventory account the
 * entry needs.
 *
 * `amount` is not accepted. What gets carried to cost of goods sold is the
 * operation's unclosed balance, computed from what it actually consumed; a
 * figure typed by hand would be a number nothing reconciles against.
 */
class CloseCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operations.close_cost_center') ?? false;
    }

    public function rules(): array
    {
        return [
            'delivery_voucher_id' => [
                'nullable',
                'integer',
                Rule::exists('delivery_vouchers', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
