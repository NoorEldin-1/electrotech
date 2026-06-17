<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LostReason;
use App\Enums\ProjectStatus;
use App\Events\OperationActivated;
use App\Models\Project;
use App\Models\ProjectOffer;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for every state transition in the Sales pipeline.
 *
 *   Draft → Tender → InHand → InProgress (= Active Operation)
 *                                  ↓
 *                                Lost
 *
 * Pre-conditions for every transition live here (not in the resources),
 * so the same rules apply whether a user clicks an Action button, an
 * API caller hits an endpoint, or a future scheduled job triggers it.
 * Violations throw DomainException — the calling layer is responsible
 * for surfacing the message to the user.
 */
class SalesPipelineService
{
    /**
     * Promote a Draft project into the Tender list once the first
     * financial+technical offer is on file. The Sales team's intake
     * form leaves projects in Draft until the offer is recorded so
     * Tender always carries a meaningful "current offer" column.
     */
    public function moveToTender(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::Draft]);

        if ($project->offers()->count() === 0) {
            throw new DomainException('Cannot move to Tender: at least one offer must be recorded first.');
        }

        $project->status = ProjectStatus::Tender;
        $project->save();

        // Keep the bell in sync: a fresh Tender with no priced offer earns the
        // "missing offer" error alert immediately, not on the next schedule.
        app(SalesAlertService::class)->reconcileOperationAlerts();
    }

    /**
     * Client has accepted in principle and asked Sales to prepare the SMB —
     * the same artifact as the project's Submittal (شريحة 11). The SMB is a
     * file, not a note: callers upload it as a Submittal attachment before this
     * runs, so we derive the In-Hand SMB status from whether that file is on
     * file yet ('submitted' if uploaded, 'pending' until it is).
     */
    public function moveToInHand(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::Tender]);

        $project->status = ProjectStatus::InHand;
        $project->smb_status = $project->hasSmb() ? 'submitted' : 'pending';
        $project->save();

        // Sync the bell: clear the Tender "missing offer" alert this operation
        // may have carried, and raise the "missing SMB" alert if it arrived
        // without an SMB on file.
        app(SalesAlertService::class)->reconcileOperationAlerts();
    }

    /**
     * Final step before manufacturing starts. Both signals — consultant
     * acceptance email AND manager approval — must be on file. We mark
     * the latest offer as is_winning so analytics can attribute revenue
     * to the right price point.
     */
    public function moveToActive(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::InHand]);

        if ($project->acceptance_email_at === null) {
            throw new DomainException('Cannot move to Active: consultant acceptance email date is missing.');
        }

        if ($project->manager_approved_at === null) {
            throw new DomainException('Cannot move to Active: manager approval is required.');
        }

        DB::transaction(function () use ($project) {
            $project->status = ProjectStatus::InProgress;

            // Slide 1: the End Date is set automatically the moment the
            // operation graduates from the Sales pipeline into Active
            // Operations. Only fill it when Sales left it blank — never
            // overwrite a date entered by hand.
            if ($project->end_date === null) {
                $project->end_date = now()->toDateString();
            }

            $project->save();

            $latest = $project->latestOffer;
            if ($latest !== null) {
                $project->offers()->where('id', '!=', $latest->id)->update(['is_winning' => false]);
                $latest->is_winning = true;
                $latest->save();
            }
        });

        // Cascade the operation to all departments (الإدارة العامة): notify
        // each department that a new operation is active. Dispatched after the
        // transaction commits so listeners see the persisted InProgress state.
        OperationActivated::dispatch($project);

        // The operation has left the In-Hand stage, so its "missing SMB" alert
        // (if any) no longer applies — drop it from the bell.
        app(SalesAlertService::class)->reconcileOperationAlerts();
    }

    /**
     * Approve the project as a manager. Separate from moveToActive so
     * a Sales user can fill in the acceptance email date, then a
     * Sales_Manager (with projects.manager_approve) signs off, then
     * either of them can hit Action.
     */
    public function managerApprove(Project $project): void
    {
        $project->manager_approved_at = now();
        $project->manager_approved_by = Auth::id();
        $project->save();
    }

    /**
     * Cancel a Sales-stage project to the Lost list. Reason and (optional)
     * winning competitor are captured so the year-end loss analysis has
     * something to chew on. Lost is terminal — no transitions out.
     */
    public function cancelToLost(
        Project $project,
        LostReason $reason,
        ?string $note = null,
        ?string $winningCompetitor = null,
    ): void {
        $this->assertStatus($project, [ProjectStatus::Tender, ProjectStatus::InHand]);

        $project->status = ProjectStatus::Lost;
        $project->lost_reason = $reason;
        $project->lost_reason_note = $note;
        $project->winning_competitor = $winningCompetitor;
        $project->save();

        // A lost operation leaves the pipeline — clear any bell alert it held.
        app(SalesAlertService::class)->reconcileOperationAlerts();
    }

    public function setAlarm(Project $project, CarbonInterface $when, ?string $note = null): void
    {
        $project->alarm_at = $when;
        $project->alarm_note = $note;
        $project->save();
    }

    public function clearAlarm(Project $project): void
    {
        $project->alarm_at = null;
        $project->alarm_note = null;
        $project->save();
    }

    /**
     * Record a new offer. Version auto-increments per project, and the
     * submitter defaults to the currently-authenticated user.
     */
    public function recordOffer(Project $project, array $data): ProjectOffer
    {
        return DB::transaction(function () use ($project, $data) {
            return $project->offers()->create([
                'version' => ProjectOffer::nextVersionFor($project->id),
                'financial_amount' => $data['financial_amount'],
                'submitted_at' => $data['submitted_at'] ?? now(),
                'submitted_by' => $data['submitted_by'] ?? Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<int, ProjectStatus>  $allowed
     */
    private function assertStatus(Project $project, array $allowed): void
    {
        if (! in_array($project->status, $allowed, true)) {
            $current = $project->status?->value ?? 'unknown';
            $allowedList = implode(', ', array_map(fn (ProjectStatus $s) => $s->value, $allowed));
            throw new DomainException(
                "Illegal transition: project is '{$current}', expected one of [{$allowedList}]."
            );
        }
    }
}
