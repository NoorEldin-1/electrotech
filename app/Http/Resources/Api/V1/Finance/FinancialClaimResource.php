<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Finance;

use App\Http\Api\EnumPresenter;
use App\Http\Resources\Api\V1\Concerns\SerializesDecimals;
use App\Models\FinancialClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * مطالبة مالية — a claim raised against a customer for an operation.
 *
 * Draft → **submit** → Submitted → **collect** → Collected, each its own
 * endpoint and its own permission. Raising a claim and deciding it has been
 * paid are different acts by different people.
 *
 * Submission is gated on the work actually being deliverable: the operation is
 * Completed, or at least one delivery to the customer is active. Claiming for
 * work that has not been delivered is how a receivable becomes a dispute.
 *
 * A claim also collects itself when payments allocated to it add up to its
 * amount, which is why `collect` is usually only called by hand for cash that
 * arrived outside the platform.
 *
 * @mixin FinancialClaim
 */
class FinancialClaimResource extends JsonResource
{
    use SerializesDecimals;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'financial_claim',
            'claim_number' => $this->claim_number,
            'status' => EnumPresenter::present($this->status),

            'project_id' => $this->project_id,
            'customer_id' => $this->customer_id,

            'claim_date' => $this->claim_date?->toDateString(),
            'amount' => $this->money($this->amount),
            'description' => $this->description,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'collected_at' => $this->collected_at?->toIso8601String(),
            'created_by' => $this->created_by,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),

            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),

            // How much of the claim has arrived. Summed on the detail endpoint
            // only — a list of claims would pay for it per row.
            'paid_amount' => $this->when(
                isset($this->additional['paid_amount']),
                fn () => $this->money($this->additional['paid_amount']),
            ),
        ];
    }
}
