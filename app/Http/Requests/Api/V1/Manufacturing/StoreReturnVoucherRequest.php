<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use App\Models\ReturnVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open a draft return voucher for a work order.
 *
 * Like the issue voucher, lines are not accepted: the server pre-fills one
 * line per material the order was actually issued, each at quantity zero, and
 * the warehouse raises the few that came back. Scrap items are left out —
 * scrap does not go back into raw stock.
 */
class StoreReturnVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ReturnVoucher::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => [
                'required',
                'integer',
                Rule::exists('work_orders', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
