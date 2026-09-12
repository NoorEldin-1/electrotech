<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Manufacturing;

use App\Http\Api\EnumPresenter;
use App\Models\QualitySheet;
use App\Models\QualitySheetLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ورقة الجودة — the quality sheet.
 *
 * A draft is opened automatically the moment manufacturing finishes, so a
 * client never creates one directly for an order that already has one.
 * Lifecycle: Draft → QaFilled (QA signs the readings) → Approved (the factory
 * manager's final sign-off, which announces to every department that the
 * operation has finished manufacturing).
 *
 * `specs` is a SNAPSHOT taken from the work order when the sheet was opened,
 * not a live view of it. Editing the order afterwards leaves an existing sheet
 * alone on purpose — a printed certificate must keep saying what was actually
 * tested. QA may still correct the snapshot on the sheet itself.
 *
 * Each line carries three pass/fail checks and five electrical tests, and each
 * electrical test has TWO readings (`r1`, `r2`), which is how the paper form
 * is laid out. The readings are free text, not numbers: the sheet records what
 * the meter showed, including entries like "OK" or "—".
 *
 * @mixin QualitySheet
 */
class QualitySheetResource extends JsonResource
{
    /**
     * The electrical test columns, mapped from the API's nested shape to the
     * two database columns behind each one. Shared with the write request so
     * the read and write shapes cannot drift apart.
     *
     * @var array<string, array{r1: string, r2: string}>
     */
    public const TEST_COLUMNS = [
        'pe_l123n' => ['r1' => 'test_pe_l123n_r1', 'r2' => 'test_pe_l123n_r2'],
        'fe_l123n' => ['r1' => 'test_fe_l123n_r1', 'r2' => 'test_fe_l123n_r2'],
        'n_l12l3' => ['r1' => 'test_n_l12l3_r1', 'r2' => 'test_n_l12l3_r2'],
        'l1_l2l3' => ['r1' => 'test_l1_l2l3_r1', 'r2' => 'test_l1_l2l3_r2'],
        'l2_l3' => ['r1' => 'test_l2_l3_r1', 'r2' => 'test_l2_l3_r2'],
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'quality_sheet',
            'sheet_number' => $this->sheet_number,
            'status' => EnumPresenter::present($this->status),
            'test_date' => $this->test_date?->toDateString(),

            'work_order_id' => $this->work_order_id,
            'operation_name' => $this->operation_name,

            // Snapshot of the order's technical sheet as it stood when this
            // sheet was opened. See the class note.
            'specs' => [
                'conductor_type' => $this->conductor_type,
                'cross_section' => $this->cross_section,
                'cross_section_e' => $this->cross_section_e,
                'external_body' => $this->external_body,
                'protection_degree' => $this->protection_degree,
                'paint' => $this->paint,
                'model' => $this->model,
                'ampere' => $this->ampere,
                'poles_count' => $this->poles_count,
            ],

            'qa_filled_by' => $this->qa_filled_by,
            'qa_filled_at' => $this->qa_filled_at?->toIso8601String(),
            'qa_inspector_notes' => $this->qa_inspector_notes,
            'factory_approved_by' => $this->factory_approved_by,
            'factory_approved_at' => $this->factory_approved_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'work_order' => $this->whenLoaded('workOrder', fn () => $this->workOrder === null ? null : [
                'id' => $this->workOrder->id,
                'wo_number' => $this->workOrder->wo_number,
                'title' => $this->workOrder->title,
                'status' => EnumPresenter::present($this->workOrder->status),
            ]),

            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->map(fn (QualitySheetLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'label' => $line->label,
                    'piece_number' => $line->piece_number,

                    // علامات صح — the three pass/fail checks.
                    'checks' => [
                        'visual_quality' => (bool) $line->visual_quality,
                        'assembly' => (bool) $line->assembly,
                        'earth_bond_pe_fe' => (bool) $line->earth_bond_pe_fe,
                    ],

                    'required_size' => $line->required_size,

                    // خانات الاختبار الكهربى — two readings per test.
                    'tests' => collect(self::TEST_COLUMNS)
                        ->map(fn (array $columns) => [
                            'r1' => $line->{$columns['r1']},
                            'r2' => $line->{$columns['r2']},
                        ])
                        ->all(),

                    'notes' => $line->notes,
                ])
                ->values()
                ->all()),

            'lines_count' => $this->whenCounted('lines'),
        ];
    }
}
