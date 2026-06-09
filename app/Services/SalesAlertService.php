<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Slide 5: the system should automatically flag an operation that was added
 * without a (priced) offer. This service is the single source of truth for
 * "what counts as incomplete" — reused by the inline table indicator and by
 * the scheduled notification command.
 */
class SalesAlertService
{
    /**
     * Operations still in the Sales pipeline (Tender / In-Hand) that have no
     * offer carrying a price yet.
     *
     * @return Collection<int, Project>
     */
    public function operationsMissingOffers(): Collection
    {
        return Project::query()
            ->whereIn('status', [ProjectStatus::Tender, ProjectStatus::InHand])
            ->missingPricedOffer()
            ->orderBy('created_at')
            ->get();
    }
}
