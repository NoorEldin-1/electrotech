<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\FacilityStatus;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Finance\AllocateCreditFacilityRequest;
use App\Http\Requests\Api\V1\Finance\StoreCreditFacilityRequest;
use App\Http\Requests\Api\V1\Finance\UpdateCreditFacilityRequest;
use App\Http\Resources\Api\V1\Finance\CreditFacilityResource;
use App\Models\CreditFacility;
use App\Models\FacilityAllocation;
use App\Models\Project;
use App\Services\CreditFacilityService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 35. Credit facilities
 *
 * التسهيلات الائتمانية — a credit line, and how much of it is already
 * committed to operations.
 *
 * The rule the whole resource exists for: **a facility cannot be promised
 * twice.** An allocation is refused when it would take the committed total
 * past the limit, so read `utilization.available` on the detail endpoint and
 * show it before anyone types an amount — it is cheaper to stop a user than to
 * refuse them.
 *
 * Releasing an allocation gives the room back. Nothing here touches the
 * ledger: a facility is a commitment, not a movement.
 */
class CreditFacilityController extends ApiController
{
    public function __construct(
        private readonly CreditFacilityService $facilities,
    ) {}

    /**
     * List credit facilities
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the facility name. Example: CIB
     * @queryParam filter[status] string active, expired or closed. Example: active
     * @queryParam filter[customer] integer Customer id. Example: 7
     * @queryParam filter[account] integer Account id. Example: 1100
     * @queryParam sort string Allowed: name, limit_amount, start_date, end_date, created_at. Example: -limit_amount
     *
     * @response 200 scenario="Success" {"data":[{"id":2,"type":"credit_facility","name":"CIB overdraft","status":{"value":"active","label":"Active","color":"success"},"limit_amount":"5000000.00"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CreditFacility::class);

        $facilities = ApiQuery::for(CreditFacility::query()->withCount('allocations'), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'customer' => ApiQuery::exact('customer_id'),
                'account' => ApiQuery::exact('account_id'),
            ])
            ->allowSearch(['name'])
            ->allowSorts(['name', 'limit_amount', 'start_date', 'end_date', 'created_at'])
            ->defaultSort('name')
            ->paginate();

        return $this->respondPaginated(CreditFacilityResource::collection($facilities));
    }

    /**
     * Show a facility
     *
     * Carries `utilization` — the limit, what is committed, and what is left.
     * The list deliberately does not: working it out means summing the
     * facility's live allocations.
     *
     * @authenticated
     *
     * @urlParam credit_facility integer required The facility id. Example: 2
     *
     * @response 200 scenario="Success" {"data":{"id":2,"type":"credit_facility","name":"CIB overdraft","utilization":{"limit":"5000000.00","used":"1200000.00","available":"3800000.00","percent":"24.00"},"allocations":[{"project_id":4,"allocated_amount":"1200000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(CreditFacility $creditFacility): JsonResponse
    {
        $this->authorize('view', $creditFacility);

        return $this->respond(
            (new CreditFacilityResource(
                $creditFacility->load('allocations')->loadCount('allocations'),
            ))->additional(['utilization' => $this->facilities->utilization($creditFacility)]),
        );
    }

    /**
     * Open a credit facility
     *
     * @authenticated
     *
     * @bodyParam name string required Example: CIB overdraft
     * @bodyParam limit_amount number required The ceiling that allocations are checked against. Example: 5000000
     * @bodyParam account_id integer optional The bank account behind it. Example: 1100
     * @bodyParam customer_id integer optional When the facility is customer-specific. Example: 7
     * @bodyParam currency string optional Three letters, defaults to EGP. Example: EGP
     * @bodyParam start_date date optional Example: 2026-01-01
     * @bodyParam end_date date optional Example: 2026-12-31
     * @bodyParam status string optional active, expired or closed. Defaults to active. Example: active
     *
     * @response 201 scenario="Created" {"data":{"id":2,"type":"credit_facility","name":"CIB overdraft","limit_amount":"5000000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreCreditFacilityRequest $request): JsonResponse
    {
        $this->authorize('create', CreditFacility::class);

        $facility = CreditFacility::create(array_merge(
            ['status' => FacilityStatus::Active, 'currency' => 'EGP', 'created_by' => Auth::id()],
            $request->validated(),
        ));

        return $this->respondCreated(new CreditFacilityResource($facility));
    }

    /**
     * Update a facility
     *
     * @authenticated
     *
     * @urlParam credit_facility integer required The facility id. Example: 2
     *
     * @bodyParam limit_amount number optional Example: 6000000
     * @bodyParam status string optional Example: closed
     *
     * @response 200 scenario="Updated" {"data":{"id":2,"type":"credit_facility","limit_amount":"6000000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateCreditFacilityRequest $request, CreditFacility $creditFacility): JsonResponse
    {
        $this->authorize('update', $creditFacility);

        $creditFacility->update($request->validated());

        return $this->respond(new CreditFacilityResource($creditFacility->fresh()));
    }

    /**
     * Commit part of a facility to an operation
     *
     * Refused when the amount would take the committed total past the limit —
     * that is the whole point of the record.
     *
     * @authenticated
     *
     * @urlParam credit_facility integer required The facility id. Example: 2
     *
     * @bodyParam project_id integer required The operation the room is for. Example: 4
     * @bodyParam amount number required Example: 1200000
     * @bodyParam notes string optional Example: Letter of credit for the imported switchgear
     *
     * @response 201 scenario="Allocated" {"data":{"id":2,"type":"credit_facility","utilization":{"used":"1200000.00","available":"3800000.00"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Over the limit" {"error":{"code":"business_rule_violated","message":"Allocation exceeds the facility's available limit."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function allocate(AllocateCreditFacilityRequest $request, CreditFacility $creditFacility): JsonResponse
    {
        $this->authorize('update', $creditFacility);

        $project = Project::query()->findOrFail($request->integer('project_id'));

        $this->facilities->allocate(
            $creditFacility,
            $project,
            (float) $request->input('amount'),
            $request->input('notes'),
        );

        return $this->respondCreated(
            (new CreditFacilityResource(
                $creditFacility->fresh()->load('allocations')->loadCount('allocations'),
            ))->additional(['utilization' => $this->facilities->utilization($creditFacility->fresh())]),
        );
    }

    /**
     * Release an allocation
     *
     * Gives the room back to the facility. The allocation row stays, marked
     * released, so the history of what was committed and when survives.
     *
     * @authenticated
     *
     * @urlParam facility_allocation integer required The allocation id. Example: 14
     *
     * @response 200 scenario="Released" {"data":{"id":2,"type":"credit_facility","utilization":{"used":"0.00","available":"5000000.00"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function release(FacilityAllocation $facilityAllocation): JsonResponse
    {
        $this->authorize('update', $facilityAllocation);

        $this->facilities->release($facilityAllocation);

        $facility = $facilityAllocation->facility->fresh();

        return $this->respond(
            (new CreditFacilityResource($facility->load('allocations')->loadCount('allocations')))
                ->additional(['utilization' => $this->facilities->utilization($facility)]),
        );
    }

    /**
     * Delete a facility
     *
     * Refused while it still has allocations — releasing them first is the
     * point, because a deleted facility would leave operations believing they
     * hold room that no longer exists anywhere.
     *
     * @authenticated
     *
     * @urlParam credit_facility integer required The facility id. Example: 2
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Still allocated" {"error":{"code":"business_rule_violated","message":"Cannot delete a credit facility that still has allocations. Release them first."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(CreditFacility $creditFacility): JsonResponse
    {
        $this->authorize('delete', $creditFacility);

        if ($creditFacility->allocations()->exists()) {
            throw new DomainException(__('errors.api.facility_has_allocations'));
        }

        $creditFacility->delete();

        return $this->respondNoContent();
    }
}
