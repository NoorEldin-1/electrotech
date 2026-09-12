<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\CreditFacility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * تسهيل ائتمانى — a credit line, and how much of it is already committed.
 *
 * `utilization` is present on the detail endpoint only, because working it out
 * means summing the facility's live allocations. It is what stops a facility
 * being promised twice: an allocation is refused when it would take the
 * committed total past the limit, and `available` is the figure a client should
 * show before anyone types an amount.
 *
 * @mixin CreditFacility
 */
class CreditFacilityResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'credit_facility',
            'name' => $this->name,
            'status' => EnumPresenter::present($this->status),

            'account_id' => $this->account_id,
            'customer_id' => $this->customer_id,

            'limit_amount' => $this->money($this->limit_amount),
            'currency' => $this->currency,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Detail endpoint only — see the class note.
            'utilization' => $this->when(
                isset($this->additional['utilization']),
                fn () => [
                    'limit' => $this->money($this->additional['utilization']['limit'] ?? 0),
                    'used' => $this->money($this->additional['utilization']['used'] ?? 0),
                    'available' => $this->money($this->additional['utilization']['available'] ?? 0),

                    // Null when the limit is zero — a percentage of nothing is
                    // not zero, it is undefined, and flattening the two would
                    // draw a full gauge on an empty facility.
                    'percent' => $this->additional['utilization']['percent'] === null
                        ? null
                        : $this->decimalString($this->additional['utilization']['percent'], 2),
                ],
            ),

            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations
                ->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'project_id' => $allocation->project_id,
                    'allocated_amount' => $this->money($allocation->allocated_amount),
                    'allocated_at' => $allocation->allocated_at?->toDateString(),
                    'status' => $allocation->status,
                    'notes' => $allocation->notes,
                ])
                ->values()
                ->all()),

            'allocations_count' => $this->whenCounted('allocations'),
        ];
    }
}
