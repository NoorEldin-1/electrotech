<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Manufacturing;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Manufacturing\FillQualitySheetRequest;
use App\Http\Requests\Api\V1\Manufacturing\ReplaceQualitySheetLinesRequest;
use App\Http\Resources\Api\V1\Manufacturing\QualitySheetResource;
use App\Models\QualitySheet;
use App\Models\WorkOrder;
use App\Services\QualitySheetService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group 23. Quality sheets
 *
 * ورقة الجودة — the test record and the certificate printed from it.
 *
 * A draft sheet is opened **automatically** when a work order's manufacturing
 * is declared finished, seeded with ten blank test rows and a snapshot of the
 * order's technical specification. `POST /work-orders/{id}/quality-sheet` is
 * idempotent and returns the existing sheet rather than a second one, so a
 * client may call it freely.
 *
 * Lifecycle: Draft → **fill** (QA signs the readings) → **approve** (the
 * factory manager's final sign-off, which announces to every department that
 * the operation has finished manufacturing). Approval is final: an approved
 * sheet can no longer be edited, which is what makes the printed certificate
 * worth anything.
 *
 * Readings are written with `PUT /quality-sheets/{id}/lines`, separately from
 * the signature, so an inspector can save a partly-filled grid all afternoon
 * and sign once.
 */
class QualitySheetController extends ApiController
{
    public function __construct(
        private readonly QualitySheetService $sheets,
    ) {}

    /**
     * List quality sheets
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the sheet number or operation name. Example: QS-2026
     * @queryParam filter[status] string draft, qa_filled or approved. Example: qa_filled
     * @queryParam filter[work_order] integer Work order id. Example: 31
     * @queryParam filter[test_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: sheet_number, test_date, status, created_at. Example: -test_date
     * @queryParam include string Allowed: workOrder. Example: workOrder
     *
     * @response 200 scenario="Success" {"data":[{"id":12,"type":"quality_sheet","sheet_number":"QS-202609-0007","status":{"value":"qa_filled","label":"QA Filled","color":"warning"},"work_order_id":31,"lines_count":10}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', QualitySheet::class);

        $sheets = ApiQuery::for(QualitySheet::query()->withCount('lines'), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'work_order' => ApiQuery::exact('work_order_id'),
                'test_date' => ApiQuery::dateBetween('test_date'),
            ])
            ->allowSearch(['sheet_number', 'operation_name'])
            ->allowSorts(['sheet_number', 'test_date', 'status', 'created_at'])
            ->allowIncludes(['workOrder'])
            ->defaultSort('-test_date')
            ->paginate();

        return $this->respondPaginated(QualitySheetResource::collection($sheets));
    }

    /**
     * Show a quality sheet
     *
     * Returns the header, the specification snapshot and the full test grid.
     *
     * @authenticated
     *
     * @urlParam quality_sheet integer required The sheet id. Example: 12
     *
     * @response 200 scenario="Success" {"data":{"id":12,"type":"quality_sheet","sheet_number":"QS-202609-0007","status":{"value":"draft","label":"Draft","color":"gray"},"specs":{"protection_degree":"IP54","poles_count":4},"lines":[{"line_no":1,"checks":{"visual_quality":true,"assembly":true,"earth_bond_pe_fe":false},"tests":{"pe_l123n":{"r1":"0.12","r2":"0.13"}}}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(QualitySheet $qualitySheet): JsonResponse
    {
        $this->authorize('view', $qualitySheet);

        return $this->respond(new QualitySheetResource($this->loaded($qualitySheet)));
    }

    /**
     * Open (or fetch) the sheet for a work order
     *
     * Idempotent and race-safe: an order never has two sheets. If one already
     * exists this returns it unchanged — including a sheet that is already
     * approved — so a client can call it without checking first.
     *
     * A new sheet is seeded with ten blank rows and a **snapshot** of the
     * order's technical specification. The snapshot is taken once: editing the
     * order afterwards does not change a sheet that already exists, because a
     * certificate must keep saying what was actually tested.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Existing sheet returned" {"data":{"id":12,"type":"quality_sheet","sheet_number":"QS-202609-0007","status":{"value":"draft","label":"Draft","color":"gray"},"lines_count":10},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function ensureForWorkOrder(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('create', QualitySheet::class);

        $sheet = $this->sheets->ensureForWorkOrder($workOrder);

        return $this->respond(new QualitySheetResource($this->loaded($sheet)));
    }

    /**
     * Write the test grid
     *
     * Replaces every row in one request. The payload mirrors the read shape
     * exactly, so a client can fetch a sheet, edit it and send it back
     * unchanged in structure.
     *
     * Readings are **strings**, not numbers: the sheet records what the meter
     * showed, and the paper form it replaces legitimately carries entries like
     * "OK" or a dash.
     *
     * Refused once the sheet is approved — the policy stops it, so the answer
     * there is 403 rather than 422.
     *
     * @authenticated
     *
     * @urlParam quality_sheet integer required The sheet id. Example: 12
     *
     * @bodyParam lines object[] required The complete grid. Send `[]` to clear it.
     * @bodyParam lines[].line_no integer optional Row number on the paper form; defaults to its position. Example: 1
     * @bodyParam lines[].label string optional Example: Panel section A
     * @bodyParam lines[].piece_number string optional Example: 4
     * @bodyParam lines[].required_size string optional Example: 16mm
     * @bodyParam lines[].checks object optional The three pass/fail marks.
     * @bodyParam lines[].checks.visual_quality boolean optional Example: true
     * @bodyParam lines[].checks.assembly boolean optional Example: true
     * @bodyParam lines[].checks.earth_bond_pe_fe boolean optional Example: true
     * @bodyParam lines[].tests object optional Keys: pe_l123n, fe_l123n, n_l12l3, l1_l2l3, l2_l3 — each with two readings.
     * @bodyParam lines[].tests.pe_l123n.r1 string optional First reading. Example: 0.12
     * @bodyParam lines[].tests.pe_l123n.r2 string optional Second reading. Example: 0.13
     * @bodyParam lines[].notes string optional Example: Retested after rework
     *
     * @response 200 scenario="Written" {"data":{"id":12,"type":"quality_sheet","lines":[{"line_no":1,"checks":{"visual_quality":true,"assembly":true,"earth_bond_pe_fe":true},"tests":{"pe_l123n":{"r1":"0.12","r2":"0.13"}}}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceLines(ReplaceQualitySheetLinesRequest $request, QualitySheet $qualitySheet): JsonResponse
    {
        $this->authorize('update', $qualitySheet);

        DB::transaction(function () use ($request, $qualitySheet): void {
            $qualitySheet->lines()->delete();

            foreach ($request->array('lines') as $index => $line) {
                $checks = $line['checks'] ?? [];
                $tests = $line['tests'] ?? [];

                $payload = [
                    'line_no' => $line['line_no'] ?? $index + 1,
                    'label' => $line['label'] ?? null,
                    'piece_number' => $line['piece_number'] ?? null,
                    'required_size' => $line['required_size'] ?? null,
                    'notes' => $line['notes'] ?? null,
                    'visual_quality' => (bool) ($checks['visual_quality'] ?? false),
                    'assembly' => (bool) ($checks['assembly'] ?? false),
                    'earth_bond_pe_fe' => (bool) ($checks['earth_bond_pe_fe'] ?? false),
                ];

                foreach (QualitySheetResource::TEST_COLUMNS as $test => $columns) {
                    $payload[$columns['r1']] = $tests[$test]['r1'] ?? null;
                    $payload[$columns['r2']] = $tests[$test]['r2'] ?? null;
                }

                $qualitySheet->lines()->create($payload);
            }
        });

        return $this->respond(new QualitySheetResource($this->loaded($qualitySheet->fresh())));
    }

    /**
     * QA signs the sheet
     *
     * Moves the sheet to QA Filled and records who signed it and when. A sheet
     * may be re-signed after a re-test, right up until the factory manager
     * gives final approval — which is the point at which it freezes.
     *
     * @authenticated
     *
     * @urlParam quality_sheet integer required The sheet id. Example: 12
     *
     * @bodyParam qa_inspector_notes string optional Example: Earth bond re-measured after rework
     *
     * @response 200 scenario="Signed" {"data":{"id":12,"type":"quality_sheet","status":{"value":"qa_filled","label":"QA Filled","color":"warning"},"qa_filled_at":"2026-09-12T13:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function fill(FillQualitySheetRequest $request, QualitySheet $qualitySheet): JsonResponse
    {
        $this->authorize('fill', $qualitySheet);

        $this->sheets->fill($qualitySheet, $request->input('qa_inspector_notes'));

        return $this->respond(new QualitySheetResource($this->loaded($qualitySheet->fresh())));
    }

    /**
     * Factory manager approval
     *
     * اعتماد نهائي من مدير المصنع — the final sign-off. It requires the QA
     * department to have signed first, freezes the sheet, and announces to
     * every department that the operation has finished manufacturing.
     *
     * Retrying after the first approval is a silent success. Note the policy
     * gates on the sheet already being QA-filled, so approving a Draft answers
     * **403**, not 422 — the same order the panel applies, where the action is
     * simply not offered.
     *
     * @authenticated
     *
     * @urlParam quality_sheet integer required The sheet id. Example: 12
     *
     * @response 200 scenario="Approved" {"data":{"id":12,"type":"quality_sheet","status":{"value":"approved","label":"Approved","color":"success"},"factory_approved_at":"2026-09-12T14:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function approve(QualitySheet $qualitySheet): JsonResponse
    {
        $this->authorize('approve', $qualitySheet);

        $this->sheets->approve($qualitySheet);

        return $this->respond(new QualitySheetResource($this->loaded($qualitySheet->fresh())));
    }

    /**
     * Delete a quality sheet
     *
     * Only before final approval. An approved sheet is the evidence behind a
     * certificate that has already left the building.
     *
     * @authenticated
     *
     * @urlParam quality_sheet integer required The sheet id. Example: 12
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(QualitySheet $qualitySheet): JsonResponse
    {
        $this->authorize('delete', $qualitySheet);

        if ($qualitySheet->isApproved()) {
            throw new DomainException(__('errors.api.quality_sheet_approved'));
        }

        $qualitySheet->delete();

        return $this->respondNoContent();
    }

    private function loaded(QualitySheet $sheet): QualitySheet
    {
        return $sheet->load(['lines', 'workOrder'])->loadCount('lines');
    }
}
