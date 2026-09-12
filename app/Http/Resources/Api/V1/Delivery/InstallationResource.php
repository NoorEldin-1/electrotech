<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Delivery;

use App\Http\Api\EnumPresenter;
use App\Models\Installation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * مرحلة التركيب — installation on the customer's site.
 *
 * Pending → In Progress → Completed, each step its own endpoint. The timestamps
 * are stamped by those transitions and are not writable.
 *
 * Installation EXPENSES are deliberately not here. They reach the operation
 * through the general ledger (an expense account tagged to the operation), so
 * that the cost of an installation is accounted for the same way every other
 * cost is, rather than in a parallel field that only this screen knows about.
 *
 * @mixin Installation
 */
class InstallationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'installation',
            'status' => EnumPresenter::present($this->status),

            'project_id' => $this->project_id,
            'delivery_voucher_id' => $this->delivery_voucher_id,

            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),

            'delivery_voucher' => $this->whenLoaded('deliveryVoucher', fn () => $this->deliveryVoucher === null ? null : [
                'id' => $this->deliveryVoucher->id,
                'voucher_number' => $this->deliveryVoucher->voucher_number,
            ]),
        ];
    }
}
