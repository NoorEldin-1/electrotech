<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use App\Models\IssueVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open a draft issue voucher for a work order.
 *
 * No lines are accepted. The server pre-fills the voucher with what the order
 * STILL needs — its material plan minus what other vouchers already carry, net
 * of posted returns — and the warehouse then edits that list with
 * `PUT /issue-vouchers/{id}/lines`. Letting a client post lines at creation
 * would mean the remaining-requirement calculation could be skipped entirely,
 * which is the one thing this document exists to enforce.
 */
class StoreIssueVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', IssueVoucher::class) ?? false;
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
