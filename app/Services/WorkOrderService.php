<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Events\ManufacturingFinished;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * PMO-manager approval of the manufacturing order (مكتب ادارة المشروعات.pptx
     * سلايد 5): moves the order out of Draft into Pending, releasing it for
     * manufacturing to start. Records who approved and when.
     *
     * Idempotent: a retry after the order has already left Draft is a silent
     * success (same forgiving pattern as approveQa/finishManufacturing).
     *
     * @throws \RuntimeException if the WO is not currently a Draft
     */
    public function approveOrder(WorkOrder $workOrder): void
    {
        if ($workOrder->status !== WorkOrderStatus::Draft) {
            if ($workOrder->isOrderApproved()) {
                return;
            }

            throw new \RuntimeException(__('errors.work_order.cannot_approve_order', [
                'number' => $workOrder->wo_number,
                'status' => $workOrder->status->getLabel(),
            ]));
        }

        $this->assertPlanIsComplete($workOrder);

        $workOrder->update([
            'status' => WorkOrderStatus::Pending,
            'order_approved_by' => Auth::id(),
            'order_approved_at' => now(),
        ]);
    }

    /**
     * The manufacturing plan a work order must carry before the floor is
     * allowed to touch it: a real quantity to produce and a start/end window
     * to produce it in.
     *
     * Enforced at the two gates that lead into manufacturing (approveOrder and
     * start) rather than at completion, because by the time an order reaches
     * QA the materials have already been issued and the plan is what the
     * variance, efficiency and loss figures are measured against — a zero
     * plan silently zeroes all three (see WorkOrder::getEfficiencyAttribute).
     * Guarding the entrance means a Completed order can no longer exist
     * without a plan, which is exactly the state the E2E report found on
     * WO-202607-0002 (produced 12, planned 0, no dates).
     *
     * @throws \RuntimeException if the plan is missing or non-positive
     */
    private function assertPlanIsComplete(WorkOrder $workOrder): void
    {
        $missing = [];

        if ((float) $workOrder->planned_quantity <= 0.0) {
            $missing[] = __('resources.work_orders.fields.planned_quantity');
        }

        if ($workOrder->planned_start_date === null) {
            $missing[] = __('resources.work_orders.fields.planned_start_date');
        }

        if ($workOrder->planned_end_date === null) {
            $missing[] = __('resources.work_orders.fields.planned_end_date');
        }

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException(__('errors.work_order.incomplete_plan', [
            'number' => $workOrder->wo_number,
            'fields' => implode('، ', $missing),
        ]));
    }

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

        // Second gate: catches orders that reached Pending without passing
        // through approveOrder (legacy rows, direct status edits).
        $this->assertPlanIsComplete($workOrder);

        $workOrder->update([
            'status' => WorkOrderStatus::InProgress,
            'actual_start_date' => now(),
        ]);
    }

    /**
     * Mark manufacturing finished as a whole (التصنيع.pptx سلايد 2) — the
     * stage-independent "انتهاء التصنيع" signal. Records the finish time and the
     * manufacturing duration (from actual_start_date), then announces to every
     * department that the product is ready for delivery via ManufacturingFinished.
     *
     * Deliberately touches neither inventory nor cost: that stays in complete(),
     * behind the QA gate. This button works "regardless of the stages".
     *
     * Idempotent: a retry after the first finish is a silent success.
     *
     * @throws \RuntimeException if the WO is not currently in manufacturing
     */
    public function finishManufacturing(WorkOrder $workOrder): void
    {
        if ($workOrder->isManufacturingFinished()) {
            return;
        }

        if ($workOrder->actual_start_date === null
            || ! in_array($workOrder->status, [WorkOrderStatus::InProgress, WorkOrderStatus::QaReview], true)) {
            throw new \RuntimeException(__('errors.work_order.cannot_finish_manufacturing', [
                'number' => $workOrder->wo_number,
                'status' => $workOrder->status->getLabel(),
            ]));
        }

        $finishedAt = now();

        $workOrder->update([
            'manufacturing_finished_at' => $finishedAt,
            'manufacturing_finished_by' => Auth::id(),
            'manufacturing_duration_minutes' => (int) $workOrder->actual_start_date->diffInMinutes($finishedAt),
        ]);

        // Open a draft quality sheet for the QA department to fill and print
        // (التصنيع سلايد 2 سفلي: "عند الضغط على زر انتهاء التصنيع... ورقة الجودة").
        // Idempotent — never creates a second sheet for the same work order.
        app(QualitySheetService::class)->ensureForWorkOrder($workOrder);

        ManufacturingFinished::dispatch($workOrder);
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

        $workOrder->loadMissing(['outputItem', 'materials']);

        DB::transaction(function () use ($workOrder) {
            $produced = (float) $workOrder->produced_quantity;

            // المخطط (سلايد 9) = قيمة طلب التصنيع: خامات الأمر إن وُجدت، وإلا
            // تقدير الأمر المأخوذ من قائمة المواد وقت الإنشاء (توافقاً مع الأوامر
            // القديمة التي تعتمد على BOM فقط).
            $plannedCost = $workOrder->planned_material_cost > 0
                ? $workOrder->planned_material_cost
                : (float) $workOrder->estimated_cost;

            // المنتج الفعلي = قيمة أمر الصرف الموقّع. تُقرأ من قاعدة البيانات لأن
            // الترحيل يزيدها على نسخة أخرى من الأمر (قد تكون النسخة الحالية قديمة).
            $actualCost = (float) ($workOrder->fresh()?->actual_material_cost ?? $workOrder->actual_material_cost);

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

            // 3) Record the production entry + extracted loss. Snapshots both
            //    the quantities and the planned-vs-actual material VALUES so the
            //    الإنتاج والفاقد report reads the loss in money (سلايد 9).
            $workOrder->productionEntries()->create([
                'output_item_id' => $workOrder->output_item_id,
                'operation_name' => $workOrder->title,
                'entry_date' => now(),
                'planned_quantity' => (float) $workOrder->planned_quantity,
                'produced_quantity' => $produced,
                'scrap_quantity' => (float) $workOrder->waste_quantity,
                'planned_material_cost' => $plannedCost,
                'actual_material_cost' => $actualCost,
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
