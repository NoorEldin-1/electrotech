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

        // Per-product plan (المنتجات التامة): a product line with no quantity
        // makes the order's total a lie and leaves the material table under-
        // scaled, so it is caught at the same gate as the total.
        $workOrder->loadMissing('outputs.item');

        $unplannedProducts = $workOrder->outputs
            ->filter(fn ($output) => (float) $output->planned_quantity <= 0.0)
            ->map(fn ($output) => $output->item?->name ?? '—');

        if ($unplannedProducts->isNotEmpty()) {
            $missing[] = __('resources.work_orders.fields.output_planned_quantity')
                . ' (' . $unplannedProducts->implode('، ') . ')';
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
     * "انتهاء التصنيع" signal. Records the finish time and the manufacturing
     * duration (from actual_start_date), then announces to every department
     * that the product is ready for delivery via ManufacturingFinished.
     *
     * DOUBLE APPROVAL GATE: declaring an order finished tells the whole company
     * the product may be delivered, so it is the strictest gate on the order —
     * BOTH the PMO-manager approval (اعتماد مكتب المشروعات) and the QA sign-off
     * (اعتماد ضمان الجودة) must already be on the record. Neither one alone is
     * enough, and neither can be worked around from the form.
     *
     * Deliberately touches neither inventory nor cost: that stays in complete().
     *
     * Idempotent: a retry after the first finish is a silent success.
     *
     * @throws \RuntimeException if the order never started, is in the wrong
     *                           state, or is missing either approval
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

        $this->assertApprovedForFinish($workOrder);

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
     * The two approvals "انتهاء التصنيع" is not allowed to bypass. Reported
     * together (not one at a time) so the user sees the full list of what is
     * still owed rather than discovering it one refusal at a time.
     *
     * @throws \RuntimeException if either approval is missing
     */
    private function assertApprovedForFinish(WorkOrder $workOrder): void
    {
        $missing = [];

        if (! $workOrder->isOrderApproved()) {
            $missing[] = __('resources.work_orders.fields.order_approved_at');
        }

        if (! $workOrder->isQaApproved()) {
            $missing[] = __('resources.work_orders.fields.qa_status');
        }

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException(__('errors.work_order.finish_requires_approvals', [
            'number' => $workOrder->wo_number,
            'approvals' => implode('، ', $missing),
        ]));
    }

    /**
     * Whether the "انتهاء التصنيع" button may be shown at all — the same rule
     * the service enforces, so the UI never offers an action that will refuse.
     */
    public function canFinishManufacturing(WorkOrder $workOrder): bool
    {
        return $workOrder->actual_start_date !== null
            && ! $workOrder->isManufacturingFinished()
            && in_array($workOrder->status, [WorkOrderStatus::InProgress, WorkOrderStatus::QaReview], true)
            && $workOrder->isOrderApproved()
            && $workOrder->isQaApproved();
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
     * Submit work order for QA review — the stage where the produced and waste
     * quantities are declared. They are captured HERE and nowhere else: the
     * create/edit form no longer carries them, because a quantity that was
     * never manufactured must not be typeable at planning time.
     *
     * A multi-product order reports per product (المنتجات التامة), and the
     * order-level totals are the sums of those lines.
     *
     * @param  array<int, array{output_id?: int|string|null, produced_quantity?: float|int|string|null, waste_quantity?: float|int|string|null}>  $results
     *
     * @throws \RuntimeException if WO is not InProgress
     */
    public function submitForQa(WorkOrder $workOrder, array $results): void
    {
        if ($workOrder->status !== WorkOrderStatus::InProgress) {
            throw new \RuntimeException(__('errors.work_order.not_in_progress', ['number' => $workOrder->wo_number]));
        }

        DB::transaction(function () use ($workOrder, $results) {
            $workOrder->loadMissing('outputs');
            $outputs = $workOrder->outputs->keyBy('id');

            $totalProduced = 0.0;
            $totalWaste = 0.0;

            foreach ($results as $result) {
                $produced = (float) ($result['produced_quantity'] ?? 0);
                $waste = (float) ($result['waste_quantity'] ?? 0);

                $totalProduced += $produced;
                $totalWaste += $waste;

                if ($output = $outputs->get($result['output_id'] ?? null)) {
                    $output->update([
                        'produced_quantity' => $produced,
                        'waste_quantity' => $waste,
                    ]);
                }
            }

            $workOrder->update([
                'status' => WorkOrderStatus::QaReview,
                'produced_quantity' => $totalProduced,
                'waste_quantity' => $totalWaste,
            ]);
        });
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

        $workOrder->loadMissing(['outputItem', 'materials', 'outputs.item']);

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

            // 1) Move every finished product into finished goods, and
            // 3) record one production entry per product.
            if ($workOrder->outputs->isNotEmpty()) {
                $this->produceOutputs($workOrder, $plannedCost, $actualCost);
            } else {
                // Legacy single-product order (authored before المنتجات التامة).
                if ($workOrder->output_item_id && $produced > 0) {
                    $this->inventoryService->addStock(
                        item: $workOrder->outputItem,
                        quantity: $produced,
                        reference: $workOrder,
                        notes: "Produced via WO #{$workOrder->wo_number}",
                        warehouse: \App\Enums\WarehouseType::FinishedGoods,
                    );
                }

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
            }

            // 2) Consume this WO's work-in-progress materials (best-effort:
            //    only what was actually issued and is still on hand in WIP).
            $this->consumeWorkInProgress($workOrder);

            $workOrder->update([
                'status' => WorkOrderStatus::Completed,
                'actual_end_date' => now(),
            ]);
        });
    }

    /**
     * Produce each of the order's finished products into the finished-goods
     * warehouse and record its own production entry (الإنتاج والفاقد).
     *
     * The order's material cost is a single pot — it was issued against the
     * order, not against a product — so it is APPORTIONED across the products
     * by their share of the planned quantity, with the remainder given to the
     * last line. That keeps the report's per-product rows meaningful while its
     * totals stay penny-identical to the order's own figures.
     */
    private function produceOutputs(WorkOrder $workOrder, float $plannedCost, float $actualCost): void
    {
        $outputs = $workOrder->outputs;
        $totalPlanned = (float) $outputs->sum(fn ($output) => (float) $output->planned_quantity);

        $plannedRemaining = round($plannedCost, 2);
        $actualRemaining = round($actualCost, 2);

        foreach ($outputs->values() as $index => $output) {
            $isLast = $index === $outputs->count() - 1;
            $produced = (float) $output->produced_quantity;

            $share = $totalPlanned > 0
                ? (float) $output->planned_quantity / $totalPlanned
                : 1 / max(1, $outputs->count());

            $plannedShare = $isLast ? $plannedRemaining : round($plannedCost * $share, 2);
            $actualShare = $isLast ? $actualRemaining : round($actualCost * $share, 2);

            $plannedRemaining = round($plannedRemaining - $plannedShare, 2);
            $actualRemaining = round($actualRemaining - $actualShare, 2);

            if ($output->item_id && $produced > 0) {
                $this->inventoryService->addStock(
                    item: $output->item,
                    quantity: $produced,
                    reference: $workOrder,
                    notes: "Produced via WO #{$workOrder->wo_number}",
                    warehouse: \App\Enums\WarehouseType::FinishedGoods,
                );
            }

            $workOrder->productionEntries()->create([
                'output_item_id' => $output->item_id,
                'operation_name' => $workOrder->title,
                'entry_date' => now(),
                'planned_quantity' => (float) $output->planned_quantity,
                'produced_quantity' => $produced,
                'scrap_quantity' => (float) $output->waste_quantity,
                'planned_material_cost' => $plannedShare,
                'actual_material_cost' => $actualShare,
                'performed_by' => Auth::id(),
            ]);
        }
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
