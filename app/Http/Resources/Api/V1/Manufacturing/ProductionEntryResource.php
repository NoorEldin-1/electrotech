<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Manufacturing;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\ProductionEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * الإنتاج والفاقد — one production record per finished product per completed
 * work order.
 *
 * Entirely READ-ONLY, and not by omission: `ProductionEntryPolicy` answers
 * false to create, update and delete. These rows are written by
 * `WorkOrderService::complete()` and are the evidence behind the loss report.
 * A writable production entry would let the loss figures be edited away from
 * the stock movements that produced them, with nothing left to reconcile
 * against.
 *
 * `loss_value` is the money the scrap cost, not the quantity: scrap × the
 * actual cost per produced unit. It is the figure the value-based loss report
 * aggregates.
 *
 * @mixin ProductionEntry
 */
class ProductionEntryResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'production_entry',

            'work_order_id' => $this->work_order_id,
            'output_item_id' => $this->output_item_id,
            'operation_name' => $this->operation_name,
            'entry_date' => $this->entry_date?->toDateString(),

            'quantities' => [
                'planned' => $this->quantity($this->planned_quantity),
                'produced' => $this->quantity($this->produced_quantity),
                'scrap' => $this->quantity($this->scrap_quantity),
                'scrap_percentage' => $this->decimalString($this->scrap_percentage, 2),
            ],

            'costs' => [
                // On a multi-product order the order's single pot of material
                // cost is APPORTIONED across products by planned quantity, so
                // these rows sum back to the order's own figures exactly.
                'planned_material' => $this->money($this->planned_material_cost),
                'actual_material' => $this->money($this->actual_material_cost),

                // الفاقد بالقيمة — what the scrap cost, not how much of it
                // there was.
                'loss_value' => $this->money($this->loss_value),
                'loss_value_percentage' => $this->decimalString($this->loss_value_percentage, 2),
            ],

            'performed_by' => $this->performed_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'work_order' => $this->whenLoaded('workOrder', fn () => $this->workOrder === null ? null : [
                'id' => $this->workOrder->id,
                'wo_number' => $this->workOrder->wo_number,
                'status' => EnumPresenter::present($this->workOrder->status),
                'project_id' => $this->workOrder->project_id,
            ]),

            'output_item' => $this->whenLoaded('outputItem', fn () => $this->outputItem === null ? null : [
                'id' => $this->outputItem->id,
                'name' => $this->outputItem->name,
                'sku' => $this->outputItem->sku,
                'unit' => EnumPresenter::present($this->outputItem->unit),
            ]),
        ];
    }
}
