<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise a manufacturing order.
 *
 * The order always starts as a Draft, so the plan is allowed to be incomplete
 * here: a positive quantity and both planned dates are enforced at the gate
 * that releases the order for manufacturing (`approve-order` / `start`), not
 * at creation. Requiring them up front would stop the technical office from
 * opening an order while the dates are still being agreed, which is exactly
 * when the order is most useful to have.
 *
 * `status`, `produced_quantity` and `waste_quantity` are deliberately absent:
 * the first is a state machine driven by its own endpoints, and the other two
 * are declared at `submit-qa` by whoever actually made the product.
 */
class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WorkOrder::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Required, not optional: `work_orders.project_id` is NOT NULL and
            // the operation is the cost centre every issued material is loaded
            // onto. An order with no operation would have nowhere to put its
            // cost.
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'bom_id' => ['nullable', 'integer', Rule::exists('boms', 'id')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],

            'planned_quantity' => ['nullable', 'numeric', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],

            // المواصفات الفنية — free text on purpose. These are printed on the
            // quality certificate as written, and the shop floor's vocabulary
            // for them is not a closed set.
            'specs' => ['nullable', 'array'],
            'specs.conductor_type' => ['nullable', 'string', 'max:255'],
            'specs.cross_section' => ['nullable', 'string', 'max:255'],
            'specs.cross_section_e' => ['nullable', 'string', 'max:255'],
            'specs.external_body' => ['nullable', 'string', 'max:255'],
            'specs.protection_degree' => ['nullable', 'string', 'max:255'],
            'specs.paint' => ['nullable', 'string', 'max:255'],
            'specs.model' => ['nullable', 'string', 'max:255'],
            'specs.ampere' => ['nullable', 'string', 'max:255'],
            'specs.poles_count' => ['nullable', 'integer', 'min:0'],

            // المنتجات التامة. Optional at creation, effectively required
            // before approval — an order with no product has nothing to plan
            // materials against, and `approve-order` says so.
            'outputs' => ['nullable', 'array', 'max:100'],
            'outputs.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'outputs.*.planned_quantity' => ['required', 'numeric', 'min:0'],
            'outputs.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
