<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Manufacturing;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Item;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Models\WorkOrderOutput;
use App\Services\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * أمر التصنيع — a manufacturing order.
 *
 * Three groups of fields deserve a note, because a client that treats them as
 * ordinary columns will get them wrong:
 *
 *  - **`specs`** is the technical sheet the PMO authors (نوع الموصل، المقطع،
 *    درجة الحماية…). It is copied onto the quality sheet at the moment
 *    manufacturing finishes, so a later edit of the order does NOT change a
 *    sheet that already exists. Print from the sheet's own copy.
 *
 *  - **`quantities`** are not all writable. `planned` is the plan; `produced`
 *    and `waste` are declared once, at `submit-qa`, and can never be set
 *    through PATCH — a quantity that was never manufactured must not be
 *    typeable at planning time.
 *
 *  - **`approvals`** is a chain: PMO-manager approval releases the order from
 *    Draft, QA sign-off follows production, and `finish-manufacturing`
 *    requires BOTH. `can_finish_manufacturing` answers the same question the
 *    service enforces, so a client should hide the action rather than let the
 *    user discover the refusal.
 *
 * @mixin WorkOrder
 */
class WorkOrderResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'work_order',
            'wo_number' => $this->wo_number,
            'title' => $this->title,
            'description' => $this->description,
            'status' => EnumPresenter::present($this->status),
            'priority' => $this->priority,

            'project_id' => $this->project_id,
            'bom_id' => $this->bom_id,

            // The FIRST product of a multi-product order, kept so a client
            // written against single-product orders keeps working. `outputs`
            // is the truth — read that.
            'output_item_id' => $this->output_item_id,

            // المواصفات الفنية — the technical sheet copied onto the quality
            // sheet when manufacturing finishes.
            'specs' => [
                'conductor_type' => $this->conductor_type,
                'cross_section' => $this->cross_section,
                'cross_section_e' => $this->cross_section_e,
                'external_body' => $this->external_body,
                'protection_degree' => $this->protection_degree,
                'paint' => $this->paint,
                'model' => $this->model,
                'ampere' => $this->ampere,
                'poles_count' => $this->poles_count,
            ],

            'quantities' => [
                'planned' => $this->quantity($this->planned_quantity),
                'produced' => $this->quantity($this->produced_quantity),
                'waste' => $this->quantity($this->waste_quantity),

                // Waste as a share of what was started, produced as a share of
                // what was planned. Published rather than left to the client so
                // every screen shows the same percentage.
                'waste_percentage' => $this->decimalString($this->waste_percentage, 2),
                'efficiency_percentage' => $this->decimalString($this->efficiency, 2),
            ],

            'costs' => [
                // The estimate taken from the BOM when the order was raised.
                'estimated' => $this->money($this->estimated_cost),

                // The order's own material table priced out — what the plan
                // says this order should consume.
                //
                // The model's accessor sums the material lines in PHP, which
                // means touching it on a list page lazy-loads a relation per
                // row. The index adds `materials_plan_value` as one subquery
                // instead and we read that when it is present, exactly as
                // ProjectResource does for `has_smb` (Finding #15). The
                // accessor remains the fallback for single-record endpoints,
                // where one extra query is the cheaper answer.
                'planned_material' => $this->money($this->plannedMaterialCost()),

                // What posted issue vouchers actually loaded onto it, net of
                // posted returns.
                'actual_material' => $this->money($this->actual_material_cost),

                'variance' => $this->money($this->cost_variance),
                'variance_percentage' => $this->decimalString($this->cost_variance_percent, 2),
            ],

            'schedule' => [
                'planned_start_date' => $this->planned_start_date?->toDateString(),
                'planned_end_date' => $this->planned_end_date?->toDateString(),
                'actual_start_date' => $this->actual_start_date?->toIso8601String(),
                'actual_end_date' => $this->actual_end_date?->toIso8601String(),
                'manufacturing_finished_at' => $this->manufacturing_finished_at?->toIso8601String(),
                'manufacturing_duration_minutes' => $this->manufacturing_duration_minutes,
                'manufacturing_duration_human' => $this->manufacturing_duration_human,
            ],

            'approvals' => [
                'order_approved' => $this->isOrderApproved(),
                'order_approved_at' => $this->order_approved_at?->toIso8601String(),
                'order_approved_by' => $this->order_approved_by,
                'qa_approved' => $this->isQaApproved(),
                'qa_approved_at' => $this->qa_approved_at?->toIso8601String(),
                'qa_approved_by' => $this->qa_approved_by,
                'qa_notes' => $this->qa_notes,
                'manufacturing_finished' => $this->isManufacturingFinished(),

                // The same predicate the service enforces, so the client can
                // hide the action instead of offering one that will refuse.
                'can_finish_manufacturing' => app(WorkOrderService::class)
                    ->canFinishManufacturing($this->resource),
            ],

            'assigned_to' => $this->assigned_to,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'status' => EnumPresenter::present($this->project->status),
            ]),

            'bom' => $this->whenLoaded('bom', fn () => $this->bom === null ? null : [
                'id' => $this->bom->id,
                'bom_number' => $this->bom->bom_number,
                'status' => EnumPresenter::present($this->bom->status),
            ]),

            // المنتجات التامة — what this order produces. A multi-product
            // order plans and reports per line; the order-level quantities are
            // the sums of these.
            'outputs' => $this->whenLoaded('outputs', fn () => $this->outputs
                ->map(fn (WorkOrderOutput $output) => [
                    'id' => $output->id,
                    'item_id' => $output->item_id,
                    'item' => $this->itemSummary($output->relationLoaded('item') ? $output->item : null),
                    'planned_quantity' => $this->quantity($output->planned_quantity),
                    'produced_quantity' => $this->quantity($output->produced_quantity),
                    'waste_quantity' => $this->quantity($output->waste_quantity),
                    'notes' => $output->notes,
                ])
                ->values()
                ->all()),

            // خامات أمر التصنيع — the material plan. `is_manual` marks a line
            // a planner set by hand, which is why re-fetching the standard
            // recipe does not silently overwrite it.
            'materials' => $this->whenLoaded('materials', fn () => $this->materials
                ->map(fn (WorkOrderMaterial $material) => [
                    'id' => $material->id,
                    'item_id' => $material->item_id,
                    'item' => $this->itemSummary($material->relationLoaded('item') ? $material->item : null),
                    'quantity' => $this->quantity($material->quantity),
                    'unit_cost' => $this->money($material->unit_cost),
                    'line_value' => $this->money((float) $material->quantity * (float) $material->unit_cost),
                    'is_manual' => (bool) $material->is_manual,
                    'notes' => $material->notes,
                ])
                ->values()
                ->all()),

            'outputs_count' => $this->whenCounted('outputs'),
            'materials_count' => $this->whenCounted('materials'),
            'issue_vouchers_count' => $this->whenCounted('issueVouchers'),
            'quality_sheets_count' => $this->whenCounted('qualitySheets'),
        ];
    }

    /**
     * The order's material plan priced out.
     *
     * The index attaches `materials_plan_value` as a single subquery, so the
     * presence of the ATTRIBUTE — not its value — is what decides which source
     * to read. An order with no material lines sums to SQL NULL, and a
     * null-coalesce would fall through to the accessor on exactly those rows,
     * lazy-loading a relation per row and reintroducing the N+1 this exists to
     * avoid.
     */
    private function plannedMaterialCost(): float
    {
        return array_key_exists('materials_plan_value', $this->resource->getAttributes())
            ? (float) $this->resource->getAttribute('materials_plan_value')
            : (float) $this->planned_material_cost;
    }

    private function itemSummary(?Item $item): ?array
    {
        return $item === null ? null : [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'unit' => EnumPresenter::present($item->unit),
        ];
    }
}
