<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\BomItem;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Start a work order: changes status to InProgress and records actual start time.
     *
     * @throws \RuntimeException if WO is not in Pending state
     */
    public function start(WorkOrder $workOrder): void
    {
        if ($workOrder->status !== WorkOrderStatus::Pending) {
            throw new \RuntimeException(
                "Work Order #{$workOrder->wo_number} cannot be started. Current status: {$workOrder->status->getLabel()}"
            );
        }

        $workOrder->update([
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now(),
        ]);
    }

    /**
     * Issue materials from warehouse to the work order based on BOM.
     * Deducts stock for each BOM item using the InventoryService.
     *
     * @throws \RuntimeException if insufficient stock or no BOM linked
     */
    public function issueMaterials(WorkOrder $workOrder): void
    {
        if (! $workOrder->bom) {
            throw new \RuntimeException(
                "Work Order #{$workOrder->wo_number} has no linked BOM. Cannot issue materials."
            );
        }

        \App\Jobs\ProcessWorkOrderMaterialsJob::dispatch($workOrder);
    }

    /**
     * Submit work order for QA review.
     * Enforces QA Gate: WO cannot complete without QA approval.
     *
     * @throws \RuntimeException if WO is not InProgress
     */
    public function submitForQa(WorkOrder $workOrder, float $producedQuantity, float $wasteQuantity): void
    {
        if ($workOrder->status !== WorkOrderStatus::InProgress) {
            throw new \RuntimeException(
                "Work Order #{$workOrder->wo_number} must be InProgress to submit for QA."
            );
        }

        $workOrder->update([
            'status' => WorkOrderStatus::QaReview,
            'produced_quantity' => $producedQuantity,
            'waste_quantity' => $wasteQuantity,
        ]);
    }

    /**
     * QA approves the work order. This is the mandatory QA Gate per PDF page 10.
     * Work cannot be completed without explicit QA approval.
     *
     * @throws \RuntimeException if WO is not in QaReview state
     */
    public function approveQa(WorkOrder $workOrder, ?string $qaNotes = null): void
    {
        if ($workOrder->status !== WorkOrderStatus::QaReview) {
            throw new \RuntimeException(
                "Work Order #{$workOrder->wo_number} is not pending QA review."
            );
        }

        $workOrder->update([
            'qa_approved_by' => Auth::id(),
            'qa_approved_at' => now(),
            'qa_notes' => $qaNotes,
        ]);
    }

    /**
     * Complete a work order. Requires QA approval (QA Gate enforcement).
     *
     * @throws \RuntimeException if QA gate not passed
     */
    public function complete(WorkOrder $workOrder): void
    {
        if (! $workOrder->isQaApproved()) {
            throw new \RuntimeException(
                "Work Order #{$workOrder->wo_number} cannot be completed without QA approval. QA Gate is mandatory."
            );
        }

        if ($workOrder->status !== WorkOrderStatus::QaReview) {
            throw new \RuntimeException(
                "Work Order #{$workOrder->wo_number} must be in QA Review to complete."
            );
        }

        DB::transaction(function () use ($workOrder) {
            $workOrder->update([
                'status' => WorkOrderStatus::Completed,
                'actual_end_date' => now(),
            ]);
        });
    }
}
