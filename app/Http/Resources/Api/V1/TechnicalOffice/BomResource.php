<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\TechnicalOffice;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\Bom;
use App\Models\BomItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bill of materials (قائمة المواد).
 *
 * Two kinds share this shape and are told apart by `scope`:
 *
 *  - a **project** BOM lists what one operation needs;
 *  - a **standard** BOM (`output_item_id` set) is the fixed recipe for
 *    manufacturing a product — تركيبة المنتج القياسية — and is what a work
 *    order's material plan is derived from.
 *
 * @mixin Bom
 */
class BomResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'bom',

            // Published as an explicit field rather than leaving the client to
            // infer it from `output_item_id !== null`. The two kinds behave
            // differently downstream, so naming the distinction keeps that
            // rule out of Dart.
            'scope' => $this->output_item_id !== null ? 'standard' : 'project',

            'project_id' => $this->project_id,
            'output_item_id' => $this->output_item_id,
            'version' => (int) $this->version,
            'status' => EnumPresenter::present($this->status),
            'notes' => $this->notes,

            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded(
                'project',
                fn () => $this->project === null ? null : [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'code' => $this->project->code,
                ],
            ),

            'output_item' => $this->whenLoaded(
                'outputItem',
                fn () => $this->outputItem === null ? null : [
                    'id' => $this->outputItem->id,
                    'name' => $this->outputItem->name,
                    'sku' => $this->outputItem->sku,
                ],
            ),

            'prepared_by' => $this->whenLoaded(
                'preparedBy',
                fn () => $this->preparedBy === null ? null : [
                    'id' => $this->preparedBy->id,
                    'name' => $this->preparedBy->name,
                ],
            ),

            'approved_by' => $this->whenLoaded(
                'approvedBy',
                fn () => $this->approvedBy === null ? null : [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                ],
            ),

            'items' => $this->whenLoaded('items', fn () => $this->items
                ->map(fn (BomItem $line) => [
                    'id' => $line->id,
                    'item_id' => $line->item_id,
                    'item' => $line->relationLoaded('item') && $line->item !== null
                        ? [
                            'id' => $line->item->id,
                            'name' => $line->item->name,
                            'sku' => $line->item->sku,
                            'unit' => EnumPresenter::present($line->item->unit),
                            'unit_cost' => $this->money($line->item->unit_cost),
                        ]
                        : null,

                    'quantity' => $this->quantity($line->quantity),

                    // The allowance for offcuts and spoilage. It is not
                    // decoration: the reservation and the work-order material
                    // plan both use the waste-adjusted figure, so a client
                    // showing only `quantity` would understate what the job
                    // actually consumes.
                    'waste_percentage' => $this->money($line->waste_percentage),
                    'total_required_quantity' => $this->quantity($line->total_required_quantity),

                    'notes' => $line->notes,
                ])
                ->values()
                ->all()),

            'items_count' => $this->whenCounted('items'),

            // Σ(quantity × the item's current unit cost). A live figure, not a
            // frozen one: it moves when purchase prices move, which is what
            // makes it useful for re-costing a job before it is released.
            'total_cost' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->money($this->total_cost),
            ),
        ];
    }
}
