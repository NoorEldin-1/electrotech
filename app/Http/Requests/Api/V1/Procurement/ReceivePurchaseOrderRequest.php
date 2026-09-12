<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    /**
     * The panel gates receiving on the bare `purchase_orders.receive`
     * permission and has no policy method for it, so the request checks the
     * same string rather than inventing a second gate.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('purchase_orders.receive') ?? false;
    }

    /**
     * `items` is keyed by **purchase-order-item id**, not item id: one order
     * may carry the same material on two lines at two prices, and collapsing
     * them would post the wrong unit cost into stock.
     *
     * The per-line cap against `remaining_quantity` is not expressed here. It
     * lives in PurchaseOrderService::receiveItems, where the row is read under
     * a lock — validating it here would read the quantity outside that lock
     * and let two concurrent receipts both pass a check that only one of them
     * should.
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['numeric', 'min:0'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
