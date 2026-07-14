<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QualitySheetStatus;
use App\Events\QualitySheetApproved;
use App\Models\QualitySheet;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ورقة الجودة — manages the quality sheet lifecycle (التصنيع سلايد 2 سفلي + 3):
 * a draft is created when manufacturing finishes, the QA department fills the
 * test results and signs, then the factory manager approves — the approval
 * announces to every department that the operation has finished manufacturing.
 */
class QualitySheetService
{
    /**
     * Number of blank test rows (بنود) seeded on a fresh sheet, matching the
     * paper form's grid.
     */
    private const DEFAULT_LINES = 10;

    /**
     * Find or create the (latest non-redundant) quality sheet for a work order.
     * Called when manufacturing finishes so the QA department has a draft to
     * fill ("عند الضغط على زر انتهاء التصنيع... ورقة الجودة"). Idempotent and
     * race-safe: never creates a second sheet for the same work order.
     */
    public function ensureForWorkOrder(WorkOrder $workOrder): QualitySheet
    {
        if ($existing = $workOrder->qualitySheets()->orderByDesc('id')->first()) {
            return $existing;
        }

        return Cache::lock('quality_sheet:wo_' . $workOrder->id, 10)->block(5, function () use ($workOrder) {
            return DB::transaction(function () use ($workOrder) {
                // Re-check under the lock in case a concurrent finish created it.
                if ($existing = $workOrder->qualitySheets()->orderByDesc('id')->first()) {
                    return $existing;
                }

                $workOrder->loadMissing(['outputItem', 'project']);

                $sheet = QualitySheet::create(array_merge(
                    [
                        'sheet_number' => QualitySheet::generateSheetNumber(),
                        'work_order_id' => $workOrder->id,
                        'test_date' => now(),
                        'status' => QualitySheetStatus::Draft,
                        'created_by' => Auth::id(),
                    ],
                    self::specSnapshotFrom($workOrder),
                ));

                for ($i = 1; $i <= self::DEFAULT_LINES; $i++) {
                    $sheet->lines()->create(['line_no' => $i]);
                }

                return $sheet;
            });
        });
    }

    /**
     * Snapshot the operation-data header from the manufacturing order
     * (سلايد 2: بيانات العملية تُملأ تلقائياً بناءً على أمر التصنيع). Copied
     * once at sheet creation so it stays stable even if the order is later
     * edited; the QA department may still adjust it on the sheet. Also used by
     * QualitySheetResource when a work order is picked in the form.
     *
     * @return array<string, mixed>
     */
    public static function specSnapshotFrom(WorkOrder $workOrder): array
    {
        return [
            'operation_name' => $workOrder->project?->name ?? $workOrder->title,
            'conductor_type' => $workOrder->conductor_type,
            'cross_section' => $workOrder->cross_section,
            'cross_section_e' => $workOrder->cross_section_e,
            'external_body' => $workOrder->external_body,
            'protection_degree' => $workOrder->protection_degree,
            'paint' => $workOrder->paint,
            'model' => $workOrder->model,
            'ampere' => $workOrder->ampere,
            'poles_count' => $workOrder->poles_count,
        ];
    }

    /**
     * QA department signs off the filled sheet. The test results themselves are
     * edited inline through the Filament repeater; this flips the status and
     * records who filled it. Idempotent re-fills are allowed (re-testing) until
     * the factory manager has given final approval.
     *
     * @throws \RuntimeException if the sheet is already approved
     */
    public function fill(QualitySheet $sheet, ?string $inspectorNotes = null): void
    {
        if ($sheet->isApproved()) {
            throw new \RuntimeException(__('errors.quality_sheet.already_approved', ['number' => $sheet->sheet_number]));
        }

        $sheet->update([
            'status' => QualitySheetStatus::QaFilled,
            'qa_filled_by' => Auth::id(),
            'qa_filled_at' => now(),
            'qa_inspector_notes' => $inspectorNotes ?? $sheet->qa_inspector_notes,
        ]);
    }

    /**
     * Factory manager final approval (اعتماد نهائي من مدير المصنع). Requires the
     * QA department to have filled it first, then announces to every department
     * that the operation has finished manufacturing via QualitySheetApproved.
     *
     * Idempotent: a retry after the first approval is a silent success.
     *
     * @throws \RuntimeException if the sheet has not been QA-filled
     */
    public function approve(QualitySheet $sheet): void
    {
        if ($sheet->isApproved()) {
            return;
        }

        if (! $sheet->isQaFilled()) {
            throw new \RuntimeException(__('errors.quality_sheet.not_filled', ['number' => $sheet->sheet_number]));
        }

        $sheet->update([
            'status' => QualitySheetStatus::Approved,
            'factory_approved_by' => Auth::id(),
            'factory_approved_at' => now(),
        ]);

        QualitySheetApproved::dispatch($sheet);
    }
}
