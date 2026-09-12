<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\TechnicalOffice;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\TechnicalOffice\ReplaceBomItemsRequest;
use App\Http\Requests\Api\V1\TechnicalOffice\StoreBomRequest;
use App\Http\Requests\Api\V1\TechnicalOffice\UpdateBomRequest;
use App\Http\Resources\Api\V1\TechnicalOffice\BomResource;
use App\Models\Bom;
use App\Models\Item;
use App\Services\BomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group 14. Bills of materials
 *
 * The Technical Office's output (قائمة المواد): what a job, or a product, is
 * made of.
 *
 * A BOM is either **project-scoped** (this operation needs these materials) or
 * **standard** (`output_item_id` set — the fixed recipe for building this
 * product, تركيبة المنتج القياسية). Both live here; `scope` tells them apart.
 *
 * The state machine is Draft → Pending approval → Approved, and approving
 * supersedes the previously approved version of the same subject. That matters
 * downstream: stock reservations and a work order's material plan both read
 * "the latest approved BOM", so leaving two approved would make which recipe a
 * job used depend on a tiebreak rather than a decision.
 */
class BomController extends ApiController
{
    public function __construct(private readonly BomService $boms) {}

    /**
     * List bills of materials
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[project] integer Only BOMs for this operation. Example: 1
     * @queryParam filter[output_item] integer Only standard BOMs building this product. Example: 12
     * @queryParam filter[scope] string Either `standard` or `project`. Example: standard
     * @queryParam filter[status] string One or more bom_status values, comma-separated. Example: approved
     * @queryParam sort string Allowed: version, status, created_at, approved_at. Example: -version
     * @queryParam include string Allowed: project, outputItem, preparedBy, approvedBy. Example: project
     * @queryParam updated_after string ISO-8601 timestamp for cache refresh. Example: 2026-08-01T10:00:00Z
     *
     * @response 200 scenario="Success" {"data":[{"id":3,"type":"bom","scope":"project","project_id":1,"version":2,"status":{"value":"approved","label":"Approved","color":"success"},"items_count":14}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bom::class);

        $boms = ApiQuery::for(Bom::query()->withCount('items'), $request)
            ->allowFilters([
                'project' => ApiQuery::exact('project_id'),
                'output_item' => ApiQuery::exact('output_item_id'),
                'status' => ApiQuery::exact('status'),

                // The distinction the resource publishes as `scope`, expressed
                // as a filter so a client asking for the standard catalogue
                // does not have to know it is really a null check.
                'scope' => fn ($query, $value) => $value === 'standard'
                    ? $query->whereNotNull('output_item_id')
                    : $query->whereNull('output_item_id'),
            ])
            ->allowSorts(['version', 'status', 'created_at', 'approved_at'])
            ->allowIncludes(['project', 'outputItem', 'preparedBy', 'approvedBy'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->respondPaginated(BomResource::collection($boms));
    }

    /**
     * Show a bill of materials
     *
     * Carries every material line, each with its item and its waste-adjusted
     * required quantity, plus the live total cost.
     *
     * @authenticated
     *
     * @urlParam bom integer required The BOM id. Example: 3
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"bom","scope":"project","version":2,"status":{"value":"approved","label":"Approved","color":"success"},"total_cost":"84250.00","items":[{"id":41,"item_id":7,"quantity":"120.0000","waste_percentage":"5.00","total_required_quantity":"126.0000"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(Bom $bom): JsonResponse
    {
        $this->authorize('view', $bom);

        return $this->respond(new BomResource(
            $bom->load(['items.item', 'project', 'outputItem', 'preparedBy', 'approvedBy']),
        ));
    }

    /**
     * Create a bill of materials
     *
     * Give it either a `project_id` (a BOM for one operation) or an
     * `output_item_id` (the standard recipe for a product) — one or the other,
     * never both, because the two are superseded against different subjects
     * and a row claiming to be both would supersede the wrong sibling.
     *
     * It is created as a **draft** with no lines. Send the lines with
     * `PUT /boms/{bom}/items`, then submit and approve.
     *
     * @authenticated
     *
     * @bodyParam project_id integer optional The operation this BOM is for. Required unless output_item_id is given. Example: 1
     * @bodyParam output_item_id integer optional The finished product this is the standard recipe for. Required unless project_id is given. Example: 12
     * @bodyParam version integer optional Defaults to 1. Example: 1
     * @bodyParam notes string optional Example: Revised after the site survey
     *
     * @response 201 scenario="Created" {"data":{"id":9,"type":"bom","scope":"project","project_id":1,"version":1,"status":{"value":"draft","label":"Draft","color":"gray"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreBomRequest $request): JsonResponse
    {
        $this->authorize('create', Bom::class);

        $bom = Bom::create($request->validated() + [
            'version' => $request->integer('version') ?: 1,
            'status' => \App\Enums\BomStatus::Draft,
            'prepared_by' => Auth::id(),
        ]);

        return $this->respondCreated(new BomResource(
            $bom->load(['items.item', 'project', 'outputItem']),
        ));
    }

    /**
     * Update a bill of materials
     *
     * Header fields only, and only while the BOM is not approved. An approved
     * BOM is a decision that reservations and work orders have already acted
     * on; a correction is a new version, which keeps the audit trail intact.
     *
     * @authenticated
     *
     * @urlParam bom integer required The BOM id. Example: 3
     * @bodyParam version integer optional Example: 2
     * @bodyParam notes string optional Example: Copper substituted for aluminium
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"bom","version":2},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Already approved" {"error":{"code":"business_rule_violated","message":"An approved BOM cannot be edited. Create a new version instead."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateBomRequest $request, Bom $bom): JsonResponse
    {
        $this->authorize('update', $bom);
        $this->boms->assertEditable($bom);

        $bom->update($request->validated());

        return $this->respond(new BomResource(
            $bom->fresh()->load(['items.item', 'project', 'outputItem']),
        ));
    }

    /**
     * Replace the material lines
     *
     * Sends the whole list of materials in one request; whatever was there
     * before is replaced. Same reasoning as the BOQ endpoint: a phone on a weak
     * link cannot reliably sequence per-line adds, edits and deletes, and one
     * atomic replace is a decision it can retry safely.
     *
     * Only allowed while the BOM is unapproved.
     *
     * @authenticated
     *
     * @urlParam bom integer required The BOM id. Example: 3
     * @bodyParam items object[] required The material lines. Send an empty array to clear them.
     * @bodyParam items[].item_id integer required An existing item id. Example: 7
     * @bodyParam items[].quantity number required Net quantity, before waste. Example: 120
     * @bodyParam items[].waste_percentage number optional Allowance for offcuts and spoilage; defaults to 0. The reservation and the work-order plan both use the waste-adjusted figure. Example: 5
     * @bodyParam items[].notes string optional Example: Cut to 3m lengths
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"bom","total_cost":"84250.00","items":[{"id":52,"item_id":7,"quantity":"120.0000","waste_percentage":"5.00","total_required_quantity":"126.0000"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceItems(ReplaceBomItemsRequest $request, Bom $bom): JsonResponse
    {
        $this->authorize('update', $bom);
        $this->boms->assertEditable($bom);

        DB::transaction(function () use ($request, $bom): void {
            $bom->items()->delete();

            foreach ($request->array('items') as $line) {
                $bom->items()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'waste_percentage' => $line['waste_percentage'] ?? 0,
                    'notes' => $line['notes'] ?? null,
                ]);
            }
        });

        return $this->respond(new BomResource($bom->fresh()->load('items.item')));
    }

    /**
     * Submit for approval
     *
     * Moves a draft to `pending_approval`. Refused for a BOM with no lines —
     * there would be nothing to approve.
     *
     * @authenticated
     *
     * @urlParam bom integer required The BOM id. Example: 3
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"bom","status":{"value":"pending_approval","label":"Pending Approval","color":"warning"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="No lines" {"error":{"code":"business_rule_violated","message":"A BOM must have at least one material line before it can be approved."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function submit(Bom $bom): JsonResponse
    {
        $this->authorize('update', $bom);

        $this->boms->submitForApproval($bom);

        return $this->respond(new BomResource($bom->fresh()->load('items.item')));
    }

    /**
     * Approve
     *
     * Gated by `boms.approve` — deliberately not `boms.edit`, because whoever
     * drafts a BOM is not thereby entitled to commit the company to buying it.
     * Approving supersedes the previously approved version of the same subject.
     *
     * @authenticated
     *
     * @urlParam bom integer required The BOM id. Example: 3
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"bom","status":{"value":"approved","label":"Approved","color":"success"},"approved_at":"2026-09-08T11:20:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not awaiting approval" {"error":{"code":"business_rule_violated","message":"Only a BOM awaiting approval can be approved. This one is draft."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function approve(Bom $bom): JsonResponse
    {
        // The panel gates this action on the same permission string; there is
        // no BomPolicy::approve, so the check is made directly rather than
        // inventing a policy method the panel does not use.
        $this->authorizePermission('boms.approve');

        $this->boms->approve($bom);

        return $this->respond(new BomResource(
            $bom->fresh()->load(['items.item', 'approvedBy']),
        ));
    }

    /**
     * Delete a bill of materials
     *
     * Soft delete, and only while unapproved.
     *
     * @authenticated
     *
     * @urlParam bom integer required The BOM id. Example: 3
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(Bom $bom): JsonResponse
    {
        $this->authorize('delete', $bom);
        $this->boms->assertEditable($bom);

        $bom->delete();

        return $this->respondNoContent();
    }

    /**
     * The current standard recipe for a product
     *
     * The latest **approved** standard BOM whose output is this item — the one
     * a work order for this product would be planned from. `404` when the
     * product has no approved recipe yet, which is a meaningful answer rather
     * than an error: it is exactly the check a client makes before offering
     * "build this".
     *
     * @authenticated
     *
     * @urlParam item integer required The finished-product item id. Example: 12
     *
     * @response 200 scenario="Success" {"data":{"id":8,"type":"bom","scope":"standard","output_item_id":12,"version":3,"status":{"value":"approved","label":"Approved","color":"success"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 404 scenario="No approved recipe" {"error":{"code":"not_found","message":"The requested Bom was not found."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function standardForItem(Item $item): JsonResponse
    {
        $this->authorize('viewAny', Bom::class);

        $bom = $item->latestApprovedStandardBom();

        abort_if($bom === null, 404);

        return $this->respond(new BomResource(
            $bom->load(['items.item', 'outputItem', 'approvedBy']),
        ));
    }
}
