<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\AttachmentCategory;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Sales\StoreProjectRequest;
use App\Http\Requests\Api\V1\Sales\UpdateProjectRequest;
use App\Http\Resources\Api\V1\Sales\ProjectResource;
use App\Models\Project;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 11. Operations
 *
 * Operations (العمليات) are the spine of the platform: a tender becomes an
 * in-hand job, becomes an active operation, and everything downstream — BOMs,
 * purchase orders, work orders, delivery vouchers, the cost centre — hangs off
 * one of these rows.
 *
 * This controller covers the record itself. The pipeline transitions between
 * statuses live in PipelineController, because they are state-machine moves
 * with their own pre-conditions and their own permissions, not field edits.
 */
class ProjectController extends ApiController
{
    /**
     * List operations
     *
     * The pipeline stages are statuses, so a stage list is a status filter:
     * `filter[status]=tender`, `in_hand`, `in_progress`, `lost`.
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches name, code, client name or consultant. Example: substation
     * @queryParam filter[status] string One or more project_status values, comma-separated. Example: tender,in_hand
     * @queryParam filter[customer] integer Customer id. Example: 3
     * @queryParam filter[has_alarm] boolean Only operations carrying a reminder. Example: true
     * @queryParam filter[missing_offer] boolean Only operations with no priced offer — the Sales alert. Example: true
     * @queryParam filter[created] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: name, code, status, created_at, end_date, alarm_at. Example: -created_at
     * @queryParam include string Allowed: customer, createdBy, latestOffer. Example: customer,latestOffer
     * @queryParam updated_after string ISO-8601 timestamp for cache refresh. Example: 2026-08-01T10:00:00Z
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"type":"project","name":"Substation A","code":"2026-1","status":{"value":"tender","label":"Tender","color":"info"},"client_name":"Delta Contracting","estimated_budget":"1250000.00","offers_count":2}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $baseQuery = Project::query()
            ->withCount('offers')
            // Resolved as one subquery over the whole page instead of an
            // EXISTS per row — see ProjectResource.
            ->withExists(['attachments as has_smb' => fn ($query) => $query
                ->where('category', AttachmentCategory::Submittal->value)]);

        $projects = ApiQuery::for($baseQuery, $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'customer' => ApiQuery::exact('customer_id'),
                'has_alarm' => fn ($query, $value) => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? $query->whereNotNull('alarm_at')
                    : $query->whereNull('alarm_at'),

                // The same scope the Sales bell uses, so the app's "needs a
                // price" list cannot disagree with the panel's alert.
                'missing_offer' => fn ($query, $value) => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? $query->missingPricedOffer()
                    : $query,

                'created' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSearch(['name', 'code', 'client_name', 'consultant_name'])
            ->allowSorts(['name', 'code', 'status', 'created_at', 'end_date', 'alarm_at'])
            ->allowIncludes(['customer', 'createdBy', 'latestOffer'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->respondPaginated(ProjectResource::collection($projects));
    }

    /**
     * Show an operation
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","name":"Substation A","code":"2026-1","status":{"value":"in_hand","label":"In Hand","color":"warning"},"has_smb":true,"latest_offer":{"id":4,"version":2,"grand_total":"1430000.00"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return $this->respond(new ProjectResource(
            $project->load(['customer', 'createdBy', 'latestOffer'])->loadCount('offers'),
        ));
    }

    /**
     * Create an operation
     *
     * `code` is generated by the server (`YYYY-N`, reset each calendar year)
     * and cannot be supplied — two clients racing to create an operation would
     * otherwise both pick the same number.
     *
     * A new operation lands in **Tender** unless a different status is sent.
     * That default is enforced by the model, not by this endpoint.
     *
     * @authenticated
     *
     * @bodyParam name string required Operation name. Example: Substation A — Busbar Trunking
     * @bodyParam client_name string required The client as written on the enquiry. Example: Delta Contracting
     * @bodyParam customer_id integer optional Link to a customer record when one exists. Example: 3
     * @bodyParam consultant_name string optional Example: ECG
     * @bodyParam engineer_name string optional Example: Eng. Mona Said
     * @bodyParam project_location string optional Example: 6th of October City
     * @bodyParam arrival_method string optional One of the arrival_method enum values. Example: email
     * @bodyParam electric_current string optional Example: 2500A
     * @bodyParam model string optional Example: BTS-2500
     * @bodyParam section_type string optional Example: Sandwich
     * @bodyParam poles_count integer optional Example: 5
     * @bodyParam quantity integer optional Example: 120
     * @bodyParam estimated_budget number optional Example: 1250000
     * @bodyParam start_date date optional Example: 2026-09-01
     * @bodyParam end_date date optional Example: 2026-12-15
     * @bodyParam description string optional Example: Busbar trunking for the main distribution room
     *
     * @response 201 scenario="Created" {"data":{"id":9,"type":"project","name":"Substation A","code":"2026-7","status":{"value":"tender","label":"Tender","color":"info"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $project = Project::create($request->validated() + [
            'code' => Project::generateCode(),
            'created_by' => Auth::id(),
        ]);

        return $this->respondCreated(new ProjectResource($project->load('customer')));
    }

    /**
     * Update an operation
     *
     * Field edits only. `status` is **not** accepted here: moving between
     * pipeline stages has pre-conditions and separate permissions, so it goes
     * through the transition endpoints. `code` is immutable once assigned.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     * @bodyParam name string optional Example: Substation A — Phase 2
     * @bodyParam client_name string optional Example: Delta Contracting
     * @bodyParam customer_id integer optional Example: 3
     * @bodyParam consultant_name string optional Example: ECG
     * @bodyParam engineer_name string optional Example: Eng. Mona Said
     * @bodyParam project_location string optional Example: 6th of October City
     * @bodyParam arrival_method string optional Example: email
     * @bodyParam estimated_budget number optional Example: 1300000
     * @bodyParam start_date date optional Example: 2026-09-01
     * @bodyParam end_date date optional Example: 2026-12-15
     * @bodyParam acceptance_email_at date optional Date the consultant's acceptance email arrived; required before the operation can move to Active. Example: 2026-09-20
     * @bodyParam description string optional Example: Revised scope
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","name":"Substation A — Phase 2"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return $this->respond(new ProjectResource($project->fresh()->load('customer')));
    }

    /**
     * Delete an operation
     *
     * Soft delete, and refused once the operation has produced downstream
     * documents. A BOM, purchase order, work order or delivery voucher is
     * evidence that money and material have moved; removing the operation they
     * point at would orphan the cost centre they post to.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Has downstream documents" {"error":{"code":"business_rule_violated","message":"Cannot delete an operation that already has BOMs, purchase orders, work orders or delivery vouchers."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $hasDownstream = $project->boms()->exists()
            || $project->purchaseOrders()->exists()
            || $project->workOrders()->exists()
            || $project->deliveryVouchers()->exists();

        if ($hasDownstream) {
            throw new DomainException(__('errors.api.project_has_documents'));
        }

        $project->delete();

        return $this->respondNoContent();
    }
}
