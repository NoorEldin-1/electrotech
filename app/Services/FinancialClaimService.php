<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClaimStatus;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\ProjectStatus;
use App\Models\FinancialClaim;
use App\Models\Project;
use DomainException;

/**
 * المطالبة المالية — manages the financial-claim workflow (سلايد 2). A claim
 * may only be submitted after the operation's supply/installation is complete
 * (the operation is Completed, or goods have been delivered).
 */
class FinancialClaimService
{
    /**
     * Submit a draft claim. Requires the operation to be deliverable/complete.
     */
    public function submit(FinancialClaim $claim): void
    {
        if (! $claim->isDraft()) {
            throw new DomainException(__('errors.operations.illegal_transition', [
                'current' => $claim->status->value,
                'allowed' => ClaimStatus::Draft->value,
            ]));
        }

        if (! $this->canRaiseFor($claim->project)) {
            throw new DomainException(__('errors.operations.claim_before_completion'));
        }

        $claim->update([
            'status' => ClaimStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    /**
     * Mark a submitted claim as collected.
     */
    public function collect(FinancialClaim $claim): void
    {
        if (! $claim->isSubmitted()) {
            throw new DomainException(__('errors.operations.illegal_transition', [
                'current' => $claim->status->value,
                'allowed' => ClaimStatus::Submitted->value,
            ]));
        }

        $claim->update([
            'status' => ClaimStatus::Collected,
            'collected_at' => now(),
        ]);
    }

    /**
     * A claim can be raised once supply/installation is complete: the operation
     * is Completed, or at least one delivery to the customer is active.
     */
    public function canRaiseFor(Project $project): bool
    {
        if ($project->status === ProjectStatus::Completed) {
            return true;
        }

        return $project->deliveryVouchers()
            ->where('status', DeliveryVoucherStatus::Active->value)
            ->exists();
    }
}
