<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Manufacturing;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\IssueVoucher;
use App\Models\IssueVoucherLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * إذن صرف — material issued from the raw store to a work order.
 *
 * Posting transfers every line from raw materials into work-in-progress and
 * loads the value onto the operation (the project is the cost centre). Until
 * then nothing has moved and the voucher is freely editable.
 *
 * The `excess` block is what makes this document different from every other
 * voucher in the platform. A voucher may legitimately ask for more than the
 * order's plan still needs — a part broke and has to be replaced — but only as
 * a decision somebody took and signed. `has_excess` records that it happened,
 * `excess_reason` records why, and `excess_approved_by` records who. A client
 * posting a voucher must be ready for `422 issue_excess_requires_approval`,
 * whose `details.excess` holds the offending rows.
 *
 * @mixin IssueVoucher
 */
class IssueVoucherResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'issue_voucher',
            'voucher_number' => $this->voucher_number,
            'status' => EnumPresenter::present($this->status),
            'voucher_date' => $this->voucher_date?->toDateString(),

            'work_order_id' => $this->work_order_id,

            // Derived at posting from the lines. Never sent by a client: the
            // server multiplies quantity by unit cost so the voucher, the
            // operation's actual cost and the stock ledger cannot disagree.
            'total_value' => $this->money($this->total_value),

            'excess' => [
                'has_excess' => $this->hasExcess(),
                'reason' => $this->excess_reason,
                'approved_by' => $this->excess_approved_by,
                'approved_at' => $this->excess_approved_at?->toIso8601String(),
            ],

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
                ->map(fn (IssueVoucherLine $line) => [
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
