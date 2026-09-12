<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Procurement;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PurchaseOrder::class) ?? false;
    }

    /**
     * No `po_number`, no `status`, no money. The number is minted by the
     * server, the status belongs to the approve/receive transitions, and every
     * money column is derived from the line items — a client-supplied
     * `total_amount` would let the order and its own lines disagree about what
     * the company committed to.
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],

            // Nullable on purpose: an order with no operation is stock bought
            // for the store rather than against one job.
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            'apply_profit_tax' => ['sometimes', 'boolean'],
            'expected_delivery_date' => ['nullable', 'date'],
            'supplier_contact' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
