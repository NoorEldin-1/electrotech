<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Procurement;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\StockReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hold placed on stock for an operation (حجز الكمية للعملية).
 *
 * A reservation lowers an item's *available* quantity without changing what is
 * physically on hand, so the material cannot be promised twice. Releasing it
 * gives the quantity back — normally when the materials are actually issued to
 * manufacturing.
 *
 * @mixin StockReservation
 */
class StockReservationResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'stock_reservation',
            'project_id' => $this->project_id,
            'item_id' => $this->item_id,
            'warehouse' => EnumPresenter::present($this->warehouse_type),
            'quantity' => $this->quantity($this->quantity),
            'status' => EnumPresenter::present($this->status),
            'notes' => $this->notes,

            'released_at' => $this->released_at?->toIso8601String(),
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

            'item' => $this->whenLoaded(
                'item',
                fn () => $this->item === null ? null : [
                    'id' => $this->item->id,
                    'name' => $this->item->name,
                    'sku' => $this->item->sku,
                    'unit' => EnumPresenter::present($this->item->unit),
                ],
            ),

            'created_by' => $this->whenLoaded(
                'createdBy',
                fn () => $this->createdBy === null ? null : [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ],
            ),
        ];
    }
}
