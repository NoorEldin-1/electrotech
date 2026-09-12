<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Inventory;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\DepreciationVoucher;
use App\Models\DepreciationVoucherLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A depreciation voucher (إذن إهلاك) — manufacturing loss written out of
 * work-in-progress and carried to a loss account.
 *
 * `loss_type` is the field that matters and it is not cosmetic:
 *
 *  - **abnormal** (غير طبيعي) reverses the value off the operation's cost;
 *  - **natural** (طبيعي) leaves it loaded on the operation, where the issue
 *    voucher already put it, and books the value to operating expenses.
 *
 * Choosing the wrong one misstates what the job cost, so it is an enum with two
 * cases rather than a note.
 *
 * @mixin DepreciationVoucher
 */
class DepreciationVoucherResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'depreciation_voucher',
            'voucher_number' => $this->voucher_number,
            'status' => EnumPresenter::present($this->status),
            'loss_type' => EnumPresenter::present($this->loss_type),
            'voucher_date' => $this->voucher_date?->toDateString(),

            'work_order_id' => $this->work_order_id,

            // Σ(quantity × unit cost) of the posted lines. Zero until posting,
            // because until then the quantities are still being decided.
            'total_value' => $this->money($this->total_value),

            // The balanced entry the posting produced; null on a draft.
            'journal_entry_id' => $this->journal_entry_id,

            'signed_at' => $this->signed_at?->toIso8601String(),
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'work_order' => $this->whenLoaded(
                'workOrder',
                fn () => $this->workOrder === null ? null : [
                    'id' => $this->workOrder->id,
                    'wo_number' => $this->workOrder->wo_number,
                    'project_id' => $this->workOrder->project_id,
                ],
            ),

            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn (DepreciationVoucherLine $line) => [
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

                    // Zero is normal on a draft: the voucher is pre-filled with
                    // everything that was issued to the order, and the user
                    // sets quantities only for what was actually lost. Lines
                    // left at zero are ignored when it posts.
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
