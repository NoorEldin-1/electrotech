<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Declare what was actually made (submit-qa).
 *
 * This is the ONLY place produced and waste quantities may be written, which
 * is why the create and update requests do not carry them. Results are
 * reported per product; the order-level totals are sums the service computes,
 * never a figure the client sends.
 *
 * `results` is `present` rather than `required`: a legacy single-product order
 * has no product lines to report against and sends an empty list.
 */
class SubmitWorkOrderQaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work_orders.submit_qa') ?? false;
    }

    public function rules(): array
    {
        return [
            'results' => ['present', 'array', 'max:100'],
            'results.*.output_id' => ['nullable', 'integer'],
            'results.*.produced_quantity' => ['required', 'numeric', 'min:0'],
            'results.*.waste_quantity' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
