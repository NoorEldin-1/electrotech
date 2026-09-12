<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BomStatus;
use App\Models\Bom;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The BOM state machine: Draft → Pending approval → Approved (→ Superseded).
 *
 * These rules used to live inside the Filament resource's approve action,
 * which meant they existed only where somebody clicked a button. Design rule
 * #1 in API_Development_Plan.md §1.1 says the opposite: the rule goes in a
 * service and both the panel and the API get it. Without that, the API would
 * either duplicate the checks (and drift) or skip them (and let a phone
 * approve an empty BOM that the panel would have refused).
 *
 * An approved BOM matters downstream: ReservationService reserves against the
 * latest approved version, and manufacturing derives a work order's material
 * plan from it. That is why the guards here are worth having.
 */
class BomService
{
    /**
     * Submit a draft for approval.
     *
     * @throws DomainException if the BOM is not a draft
     */
    public function submitForApproval(Bom $bom): Bom
    {
        if ($bom->status !== BomStatus::Draft) {
            throw new DomainException(__('errors.api.bom_not_pending_approval', [
                'status' => $bom->status?->value ?? 'unknown',
            ]));
        }

        $this->assertHasItems($bom);

        $bom->status = BomStatus::PendingApproval;
        $bom->save();

        return $bom;
    }

    /**
     * Approve a BOM awaiting approval, and supersede the previously approved
     * version of the same subject.
     *
     * Superseding matters: `Item::latestApprovedStandardBom()` and the
     * reservation service both pick "the latest approved" one. Leaving two
     * versions approved would make which recipe a work order used depend on
     * an ordering tiebreak rather than on a decision anyone made.
     *
     * @throws DomainException if the BOM is not awaiting approval, or has no lines
     */
    public function approve(Bom $bom): Bom
    {
        if ($bom->status !== BomStatus::PendingApproval) {
            throw new DomainException(__('errors.api.bom_not_pending_approval', [
                'status' => $bom->status?->value ?? 'unknown',
            ]));
        }

        $this->assertHasItems($bom);

        return DB::transaction(function () use ($bom): Bom {
            Bom::query()
                ->where('id', '!=', $bom->id)
                ->where('status', BomStatus::Approved)
                ->when(
                    $bom->output_item_id !== null,
                    // A standard BOM is scoped to the product it builds.
                    fn ($query) => $query->where('output_item_id', $bom->output_item_id),
                    // A project BOM is scoped to its operation. The
                    // `whereNotNull` keeps a project BOM from superseding a
                    // standard one that happens to share the project column.
                    fn ($query) => $query
                        ->where('project_id', $bom->project_id)
                        ->whereNull('output_item_id'),
                )
                ->update(['status' => BomStatus::Superseded]);

            $bom->status = BomStatus::Approved;
            $bom->approved_by = Auth::id();
            $bom->approved_at = now();
            $bom->save();

            return $bom;
        });
    }

    /**
     * An approved BOM is a decision that downstream documents have already
     * acted on, so it is read-only. A correction is a new version, which keeps
     * the audit trail intact.
     *
     * @throws DomainException if the BOM is approved
     */
    public function assertEditable(Bom $bom): void
    {
        if ($bom->status === BomStatus::Approved) {
            throw new DomainException(__('errors.api.bom_approved_is_immutable'));
        }
    }

    /**
     * @throws DomainException if the BOM has no material lines
     */
    private function assertHasItems(Bom $bom): void
    {
        if (! $bom->items()->exists()) {
            throw new DomainException(__('errors.api.bom_requires_items'));
        }
    }
}
