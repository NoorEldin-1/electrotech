<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CreditFacility;
use App\Models\FacilityAllocation;
use App\Models\Project;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * التسهيلات الائتمانية — allocates a facility's limit to operations and reports
 * utilization (سلايد 1: "مراقبة التسهيلات وتحليلها على العمليات").
 */
class CreditFacilityService
{
    /**
     * Reserve part of a facility's available limit for an operation.
     *
     * @throws DomainException if the amount exceeds the available headroom
     */
    public function allocate(CreditFacility $facility, Project $project, float $amount, ?string $notes = null): FacilityAllocation
    {
        return DB::transaction(function () use ($facility, $project, $amount, $notes) {
            $facility->refresh();

            if ($amount > $facility->available_amount + 0.001) {
                throw new DomainException(__('errors.operations.facility_exceeds_available', [
                    'amount' => $amount,
                    'available' => $facility->available_amount,
                    'facility' => $facility->name,
                ]));
            }

            return $facility->allocations()->create([
                'project_id' => $project->id,
                'allocated_amount' => $amount,
                'allocated_at' => now()->toDateString(),
                'status' => 'active',
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * Release an allocation back to the facility's available limit. Idempotent.
     */
    public function release(FacilityAllocation $allocation): void
    {
        if (! $allocation->isActive()) {
            return;
        }

        $allocation->update(['status' => 'released']);
    }

    /**
     * @return array{limit: float, used: float, available: float, percent: float|null}
     */
    public function utilization(CreditFacility $facility): array
    {
        $limit = (float) $facility->limit_amount;
        $used = $facility->used_amount;

        return [
            'limit' => $limit,
            'used' => $used,
            'available' => $limit - $used,
            'percent' => $limit > 0.0 ? ($used / $limit) * 100 : null,
        ];
    }
}
