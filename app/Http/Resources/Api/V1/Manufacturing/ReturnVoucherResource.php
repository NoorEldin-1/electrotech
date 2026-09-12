<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Manufacturing;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\ReturnVoucher;
use App\Models\ReturnVoucherLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * إذن ارتداد — the exact inverse of an issue voucher.
 *
 * Posting transfers unconsumed material out of work-in-progress back into the
 * SAME item's raw stock (no separate scrap code, so it can be re-issued) and
 * REVERSES its value off the operation and off the work order.
 *
 * Lines left at quantity zero are ignored at posting. That is deliberate: a
 * draft is pre-filled with everything the order was issued, and the warehouse
 * sets actual amounts on the few lines that came back rather than deleting
 * every line that did not.
 *
 * @mixin ReturnVoucher
 */
class ReturnVoucherResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'return_voucher',
            'voucher_number' => $this->voucher_number,
            'status' => EnumPresenter::present($this->status),
            'voucher_date' => $this->voucher_date?->toDateString(),

            'work_order_id' => $this->work_order_id,

            // Derived at posting from the lines that carried a quantity.
            'total_value' => $this->money($this->total_value),

            'notes' => $this->notes,
            'issued_by' => $this->issued_by,
            'signed_by' => $this->signed_by,
            'signed_at' => $this->signed_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'work_order' => $this->whenLoaded('workOrder', fn () => $this->workOrder === null ? null : [
                'id' => $this->workOrder->id,
                'wo_number' => $this->workOrder->wo_number,
                'title' => $this->workOrder->title,
                'status' => EnumPresenter::present($this->workOrder->status),
                'project_id' => $this->workOrder->project_id,
            ]),

            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn (ReturnVoucherLine $line) => [
                    'id' => $line->id,
                    'item_id' => $line->item_id,
                    'item' => $line->relationLoaded('item') && $line->item !== null
                        ? [
                            'id' => $line->item->id,
                            'name' => $line->item->name,
                            'sku' => $line->item->sku,
                            'unit' => EnumPresenter::present($line->item->unit),
                        ]
                        : null,
                    'quantity' => $this->quantity($line->quantity),
                    'unit_cost' => $this->money($line->unit_cost),
                    'line_value' => $this->money((float) $line->quantity * (float) $line->unit_cost),
                ])
                ->values()
                ->all()),

            'lines_count' => $this->whenCounted('lines'),
        ];
    }
}
