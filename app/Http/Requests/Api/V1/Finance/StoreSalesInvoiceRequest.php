<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Finance;

use App\Models\SalesInvoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Record an invoice against a delivered voucher.
 *
 * The voucher comes from the URL and the customer from the voucher, so an
 * invoice can never name someone other than whoever received the goods.
 *
 * The amount must not take the voucher's invoiced total past what was actually
 * delivered — that rule lives in SalesInvoicingService, where the panel obeys
 * it too, and it is what keeps "invoiced" and "delivered" reconcilable.
 */
class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SalesInvoice::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
