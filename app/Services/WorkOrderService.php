<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkOrderStatus;
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
            throw new \RuntimeException(__('errors.work_order.cannot_start', [
                'number' => $workOrder->wo_number,
                'status' => $workOrder->status->getLabel(),
            ]));
        }

        $workOrder->update([
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now(),
        ]);
    }

    /**
     * Create a DRAFT issue voucher (إذن صرف) for the work order from its BOM.
     * The warehouse then reviews and posts it, which transfers the raw
     * materials into work-in-progress and loads their value onto the project.
     *
     * @throws \RuntimeException if no BOM is linked
     */
    public function issueMaterials(WorkOrder $workOrder): \App\Models\IssueVoucher
    {
        return app(\App\Services\IssueVoucherService::class)->createFromWorkOrder($workOrder);
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
            throw new \RuntimeException(__('errors.work_order.not_in_progress', ['number' => $workOrder->wo_number]));
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
            throw new \RuntimeException(__('errors.work_order.not_pending_qa', ['number' => $workOrder->wo_number]));
        }

        $workOrder->update([
            'qa_approved_by' => Auth::id(),
            'qa_approved_at' => now(),
            'qa_notes' => $qaNotes,
        ]);
    }

    /**
     * Complete a work order (the "إجراء" of slide 9). Requires QA approval.
     * On completion it:
     *   1. produces the finished product into the finished-goods warehouse,
     *   2. consumes the materials this WO had moved into work-in-progress,
     *   3. records a production entry with the planned-vs-actual loss (الفاقد).
     *
     * @throws \RuntimeException if QA gate not passed
     */
    public function complete(WorkOrder $workOrder): void
    {
        if (! $workOrder->isQaApproved()) {
            throw new \RuntimeException(__('errors.work_order.qa_gate', ['number' => $workOrder->wo_number]));
        }

        if ($workOrder->status !== WorkOrderStatus::QaReview) {
            throw new \RuntimeException(__('errors.work_order.not_in_qa_review', ['number' => $workOrder->wo_number]));
        }

        $workOrder->loadMissing('outputItem');

        DB::transaction(function () use ($workOrder) {
            $produced = (float) $workOrder->produced_quantity;

            // 1) Move the finished product into finished goods.
            if ($workOrder->output_item_id && $produced > 0) {
                $this->inventoryService->addStock(
                    item: $workOrder->outputItem,
                    quantity: $produced,
                    reference: $workOrder,
                    notes: "Produced via WO #{$workOrder->wo_number}",
                    warehouse: \App\Enums\WarehouseType::FinishedGoods,
                );
            }

            // 2) Consume this WO's work-in-progress materials (best-effort:
            //    only what was actually issued and is still on hand in WIP).
            $this->consumeWorkInProgress($workOrder);

            // 3) Record the production entry + extracted loss.
            $workOrder->productionEntries()->create([
                'output_item_id' => $workOrder->output_item_id,
                'entry_date' => now(),
                'planned_quantity' => (float) $workOrder->planned_quantity,
                'produced_quantity' => $produced,
                'scrap_quantity' => (float) $workOrder->waste_quantity,
                'performed_by' => Auth::id(),
            ]);

            $workOrder->update([
                'status' => WorkOrderStatus::Completed,
                'actual_end_date' => now(),
            ]);
        });
    }

    /**
     * Deduct from work-in-progress the materials this work order pulled in via
     * its posted issue vouchers. Capped at the quantity actually on hand in
     * WIP so a partial / inconsistent state can never throw and block a
     * legitimate completion.
     */
    private function consumeWorkInProgress(WorkOrder $workOrder): void
    {
        $issued = \App\Models\IssueVoucherLine::query()
            ->whereHas('issueVoucher', fn ($q) => $q
                ->where('work_order_id', $workOrder->id)
                ->where('status', \App\Enums\VoucherStatus::Posted->value))
            ->selectRaw('item_id, SUM(quantity) AS qty')
            ->groupBy('item_id')
            ->get();

        foreach ($issued as $row) {
            $item = \App\Models\Item::find($row->item_id);
            if (! $item) {
                continue;
            }

            $available = $item->availableIn(\App\Enums\WarehouseType::WorkInProgress);
            $toConsume = min((float) $row->qty, $available);

            if ($toConsume <= 0) {
                continue;
            }

            $this->inventoryService->deductStock(
                item: $item,
                quantity: $toConsume,
                reference: $workOrder,
                notes: "Consumed in production for WO #{$workOrder->wo_number}",
                warehouse: \App\Enums\WarehouseType::WorkInProgress,
            );
        }
    }
}
