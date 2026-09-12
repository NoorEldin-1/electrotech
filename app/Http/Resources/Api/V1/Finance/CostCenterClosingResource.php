<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\CostCenterClosing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * إقفال مركز التكلفة — carrying an operation's accumulated cost out of
 * inventory and into cost of goods sold, once its goods have reached the
 * customer.
 *
 * Two flags describe where a row came from and what became of it:
 *
 *  - **`is_automatic`** marks a closing raised by a delivery activating, as
 *    opposed to one finance did by hand. The automatic path is deliberately
 *    silent and never fires while a work order is still open on the operation
 *    — cost would be charged to sales before it finished accruing.
 *
 *  - **`is_reversal` / `is_reversed`** are the audit trail. A posted journal
 *    entry is immutable, so undoing a closing is a *reversing* entry plus a
 *    negative closing row. The unclosed balance comes back on its own and
 *    nothing is deleted.
 *
 * @mixin CostCenterClosing
 */
class CostCenterClosingResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'cost_center_closing',

            'project_id' => $this->project_id,
            'delivery_voucher_id' => $this->delivery_voucher_id,
            'journal_entry_id' => $this->journal_entry_id,

            // Set on the row that undoes another; `is_reversal` reads it.
            'reverses_id' => $this->reverses_id,

            'amount' => $this->money($this->amount),
            'is_automatic' => (bool) $this->is_automatic,
            'is_reversal' => $this->isReversal(),
            'is_reversed' => $this->isReversed(),

            'notes' => $this->notes,
            'closed_by' => $this->closed_by,
            'closed_at' => $this->closed_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
        ];
    }
}
