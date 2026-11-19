<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\ProjectStatus;
use App\Enums\QualitySheetStatus;
use App\Enums\WorkOrderStatus;
use App\Models\DeliveryVoucherLine;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * الإدارة العامة — drives the operation lifecycle AFTER activation (سلايد 2:
 * مرحلة التصنيع → التسليم → الإتمام). Complements SalesPipelineService (which
 * owns the sales-stage transitions up to InProgress).
 *
 *   InProgress ⇄ OnHold
 *        ↓          ↓
 *      Completed (terminal)
 *
 * Every transition asserts the source status and throws DomainException on an
 * illegal move; the calling layer surfaces the message.
 */
class OperationLifecycleService
{
    /**
     * Tolerance when comparing decimal(…,4) quantities.
     */
    private const QUANTITY_EPSILON = 0.0001;

    /** Permission that unlocks forceComplete(): General Manager and Admin. */
    public const FORCE_COMPLETE_PERMISSION = 'operations.force_complete';

    /**
     * Mark an active (or on-hold) operation as completed — supply/installation
     * finished (سلايد 2: "بعد اتمام التوريد والتركيب").
     *
     * An operation is closed by what happened on the floor, not by a sales
     * click: see completionBlockers() for the three conditions.
     *
     * @throws DomainException if the status is wrong or any blocker remains
     */
    public function markCompleted(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::InProgress, ProjectStatus::OnHold]);

        $blockers = $this->completionBlockers($project);

        if ($blockers !== []) {
            throw new DomainException(__('errors.operations.completion_blocked', [
                'operation' => $project->name,
                'reasons' => implode(' ', $blockers),
            ]));
        }

        $project->status = ProjectStatus::Completed;
        $project->save();
    }

    /**
     * إنهاء استثنائي — end an operation WITHOUT passing the completion gate
     * (ruling G1). It exists for the operations that are already in flight and
     * have no manufacturing / quality / delivery record to satisfy the three
     * conditions of completionBlockers(), which would otherwise stay open
     * forever. It is deliberately heavier than markCompleted():
     *
     *   - the acting user must hold `operations.force_complete`
     *     (General Manager and Admin),
     *   - a reason is mandatory,
     *   - the reason, the user and the blockers that were bypassed are written
     *     to the activity log (event `force_completed`) in the same transaction
     *     as the status change.
     *
     * @throws DomainException if the user may not, the reason is blank, or the
     *                         operation is not active / on hold
     */
    public function forceComplete(Project $project, string $reason, User $by): void
    {
        if (! $by->can(self::FORCE_COMPLETE_PERMISSION)) {
            throw new DomainException(__('errors.operations.force_complete_forbidden'));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(__('errors.operations.force_complete_reason_required'));
        }

        $this->assertStatus($project, [ProjectStatus::InProgress, ProjectStatus::OnHold]);

        // What the gate would have said — kept as evidence of what was waived.
        $bypassed = $this->completionBlockers($project);
        $previous = $project->status;

        DB::transaction(function () use ($project, $reason, $by, $bypassed, $previous): void {
            $project->status = ProjectStatus::Completed;
            $project->save();

            activity()
                ->performedOn($project)
                ->causedBy($by)
                ->event('force_completed')
                ->withProperties([
                    'old' => ['status' => $previous?->value],
                    'attributes' => [
                        'status' => ProjectStatus::Completed->value,
                        'force_complete_reason' => $reason,
                    ],
                    'bypassed_blockers' => $bypassed,
                ])
                ->log("Operation '{$project->name}' was completed by exception: {$reason}");
        });
    }

    /**
     * Why this operation cannot be completed yet — translated reasons, empty
     * when it can. The operation ends only when:
     *
     *   (a) every manufacturing order has been finished by the factory manager
     *       ("انتهاء التصنيع"),
     *   (b) every manufacturing order has an approved quality sheet,
     *   (c) everything that was ordered has gone out on activated delivery
     *       vouchers.
     *
     * Cancelled orders are ignored. An operation with no manufacturing orders
     * at all (pure supply) has nothing to finish or inspect, so only (c)
     * applies — and since there is then no ordered quantity to compare with,
     * (c) means "at least one activated delivery voucher".
     *
     * @return array<int, string>
     */
    public function completionBlockers(Project $project): array
    {
        $blockers = [];

        $workOrders = $project->workOrders()
            ->where('status', '!=', WorkOrderStatus::Cancelled->value)
            ->with(['outputs', 'qualitySheets'])
            ->orderBy('id')
            ->get();

        foreach ($workOrders as $workOrder) {
            if (! $workOrder->isManufacturingFinished()) {
                $blockers[] = __('errors.operations.blockers.manufacturing_not_finished', [
                    'number' => $workOrder->wo_number,
                ]);
            }

            $hasPassedSheet = $workOrder->qualitySheets
                ->contains(fn ($sheet) => $sheet->status === QualitySheetStatus::Approved);

            if (! $hasPassedSheet) {
                $blockers[] = __('errors.operations.blockers.quality_sheet_not_passed', [
                    'number' => $workOrder->wo_number,
                ]);
            }
        }

        $delivered = $this->deliveredQuantities($project);

        if ($delivered->isEmpty()) {
            $blockers[] = __('errors.operations.blockers.no_active_delivery');
        }

        foreach ($this->orderedQuantities($workOrders) as $itemId => $ordered) {
            $done = (float) ($delivered[$itemId] ?? 0);

            if ($done + self::QUANTITY_EPSILON >= $ordered) {
                continue;
            }

            // A zero-delivery operation already says "no delivery"; naming
            // every item on top of that would only repeat it.
            if ($delivered->isEmpty()) {
                continue;
            }

            $blockers[] = __('errors.operations.blockers.undelivered', [
                'item' => Item::withTrashed()->find($itemId)?->name ?? '#'.$itemId,
                'delivered' => rtrim(rtrim(number_format($done, 4, '.', ''), '0'), '.'),
                'ordered' => rtrim(rtrim(number_format($ordered, 4, '.', ''), '0'), '.'),
            ]);
        }

        return $blockers;
    }

    public function canComplete(Project $project): bool
    {
        return $this->completionBlockers($project) === [];
    }

    /**
     * Quantity ordered per finished product across the (non-cancelled) orders:
     * the product rows, or — for a legacy order that has none — its single
     * output item at the order's planned quantity.
     *
     * @param  \Illuminate\Support\Collection<int, WorkOrder>  $workOrders
     * @return array<int, float> item id => ordered quantity
     */
    private function orderedQuantities($workOrders): array
    {
        $ordered = [];

        foreach ($workOrders as $workOrder) {
            if ($workOrder->outputs->isNotEmpty()) {
                foreach ($workOrder->outputs as $output) {
                    $ordered[$output->item_id] = ($ordered[$output->item_id] ?? 0) + (float) $output->planned_quantity;
                }
            } elseif ($workOrder->output_item_id !== null) {
                $ordered[$workOrder->output_item_id] = ($ordered[$workOrder->output_item_id] ?? 0)
                    + (float) $workOrder->planned_quantity;
            }
        }

        return $ordered;
    }

    /**
     * Quantity already delivered per item on ACTIVATED vouchers (both
     * signatures in, stock moved). Drafts, pending and cancelled vouchers
     * deliver nothing.
     *
     * @return \Illuminate\Support\Collection<int, float> item id => delivered quantity
     */
    private function deliveredQuantities(Project $project): \Illuminate\Support\Collection
    {
        return DeliveryVoucherLine::query()
            ->whereHas('deliveryVoucher', fn ($q) => $q
                ->where('project_id', $project->id)
                ->where('status', DeliveryVoucherStatus::Active->value))
            ->selectRaw('item_id, SUM(quantity) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id')
            ->map(fn ($total) => (float) $total);
    }

    public function putOnHold(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::InProgress]);

        $project->status = ProjectStatus::OnHold;
        $project->save();
    }

    public function resume(Project $project): void
    {
        $this->assertStatus($project, [ProjectStatus::OnHold]);

        $project->status = ProjectStatus::InProgress;
        $project->save();
    }

    /**
     * @param  array<int, ProjectStatus>  $allowed
     */
    private function assertStatus(Project $project, array $allowed): void
    {
        if (! in_array($project->status, $allowed, true)) {
            throw new DomainException(__('errors.operations.illegal_transition', [
                'current' => $project->status?->value ?? 'unknown',
                'allowed' => implode(', ', array_map(fn (ProjectStatus $s) => $s->value, $allowed)),
            ]));
        }
    }
}
