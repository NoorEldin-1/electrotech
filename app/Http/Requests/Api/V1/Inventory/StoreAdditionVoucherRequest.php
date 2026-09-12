<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Inventory;

use App\Models\AdditionVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdditionVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdditionVoucher::class) ?? false;
    }

    /**
     * No `status` and no `voucher_number`: the number is minted by the server
     * and the status belongs to the post transition. A voucher created as
     * `posted` would have added no stock and credited nobody, while claiming
     * it had.
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'voucher_date' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Required and non-empty: posting a voucher with no lines is
            // refused by the service, so accepting one here would only let a
            // client build a document it can never use.
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Somebody has to be identifiable as the source of the goods, even when
     * there is no supplier record. A voucher with neither is a receipt from
     * nobody, and the stock card's description column would have nothing to
     * show.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('supplier_id') && ! $this->filled('supplier_name')) {
                $validator->errors()->add(
                    'supplier_id',
                    'Give either supplier_id (a registered supplier) or supplier_name (free text).',
                );
            }
        });
    }
}
