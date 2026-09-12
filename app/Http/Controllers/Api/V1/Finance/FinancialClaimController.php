<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\ClaimStatus;
use App\Enums\PaymentDirection;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Finance\StoreFinancialClaimRequest;
use App\Http\Requests\Api\V1\Finance\UpdateFinancialClaimRequest;
use App\Http\Resources\Api\V1\Finance\FinancialClaimResource;
use App\Models\FinancialClaim;
use App\Models\OperationPayment;
use App\Services\FinancialClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 34. Financial claims
 *
 * المطالبات المالية — what the company is asking a customer to pay, for a
 * given operation.
 *
 * Draft → **submit** → Submitted → **collect** → Collected. Three permissions,
 * because raising a claim, sending it to the customer and declaring it paid
 * are three different acts by three different people.
 *
 * Submission is gated on the work being deliverable: the operation is
 * Completed, or at least one delivery to the customer is active. Claiming for
 * work that has not been delivered is how a receivable becomes a dispute.
 *
 * A claim also **collects itself** when payments allocated to it add up to its
 * amount, which is why `collect` is normally only called by hand for cash that
 * arrived outside the platform.
 */
class FinancialClaimController extends ApiController
{
    public function __construct(
        private readonly FinancialClaimService $claims,
    ) {}

    /**
     * List financial claims
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the claim number or description. Example: CLM-2026
     * @queryParam filter[status] string draft, submitted, collected or cancelled. Example: submitted
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[customer] integer Customer id. Example: 7
     * @queryParam filter[claim_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: claim_number, claim_date, amount, created_at. Example: -claim_date
     * @queryParam include string Allowed: project, customer. Example: customer
     *
     * @response 200 scenario="Success" {"data":[{"id":9,"type":"financial_claim","claim_number":"CLM-202609-0003","status":{"value":"submitted","label":"Submitted","color":"warning"},"amount":"250000.00"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinancialClaim::class);

        $claims = ApiQuery::for(FinancialClaim::query(), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'project' => ApiQuery::exact('project_id'),
                'customer' => ApiQuery::exact('customer_id'),
                'claim_date' => ApiQuery::dateBetween('claim_date'),
            ])
            ->allowSearch(['claim_number', 'description'])
            ->allowSorts(['claim_number', 'claim_date', 'amount', 'created_at'])
            ->allowIncludes(['project', 'customer'])
            ->defaultSort('-claim_date')
            ->paginate();

        return $this->respondPaginated(FinancialClaimResource::collection($claims));
    }

    /**
     * Show a claim
     *
     * Carries `paid_amount` — what the payments allocated to this claim add up
     * to. Summed here and not on the list, where it would cost a query per row.
     *
     * @authenticated
     *
     * @urlParam financial_claim integer required The claim id. Example: 9
     *
     * @response 200 scenario="Success" {"data":{"id":9,"type":"financial_claim","claim_number":"CLM-202609-0003","amount":"250000.00","paid_amount":"150000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(FinancialClaim $financialClaim): JsonResponse
    {
        $this->authorize('view', $financialClaim);

        $paid = (float) OperationPayment::query()
            ->where('financial_claim_id', $financialClaim->id)
            ->where('direction', PaymentDirection::Incoming->value)
            ->sum('amount');

        return $this->respond(
            (new FinancialClaimResource($financialClaim->load(['project', 'customer'])))
                ->additional(['paid_amount' => $paid]),
        );
    }

    /**
     * Raise a claim
     *
     * Always a Draft. It reaches the customer through `submit`.
     *
     * @authenticated
     *
     * @bodyParam project_id integer required The operation being claimed for. Example: 4
     * @bodyParam customer_id integer required Example: 7
     * @bodyParam claim_date date optional Defaults to today. Example: 2026-09-16
     * @bodyParam amount number required Example: 250000
     * @bodyParam description string optional Example: Supply and installation, first milestone
     *
     * @response 201 scenario="Created" {"data":{"id":9,"type":"financial_claim","claim_number":"CLM-202609-0003","status":{"value":"draft","label":"Draft","color":"gray"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreFinancialClaimRequest $request): JsonResponse
    {
        $this->authorize('create', FinancialClaim::class);

        $claim = FinancialClaim::create(array_merge($request->validated(), [
            'claim_date' => $request->input('claim_date', now()->toDateString()),
            'status' => ClaimStatus::Draft,
            'created_by' => Auth::id(),
        ]));

        return $this->respondCreated(new FinancialClaimResource(
            $claim->load(['project', 'customer']),
        ));
    }

    /**
     * Update a draft claim
     *
     * Drafts only — the policy answers **403** once the claim has been
     * submitted, because the customer is already holding a copy.
     *
     * @authenticated
     *
     * @urlParam financial_claim integer required The claim id. Example: 9
     *
     * @bodyParam amount number optional Example: 260000
     * @bodyParam description string optional Example: Supply and installation, revised scope
     *
     * @response 200 scenario="Updated" {"data":{"id":9,"type":"financial_claim","amount":"260000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateFinancialClaimRequest $request, FinancialClaim $financialClaim): JsonResponse
    {
        $this->authorize('update', $financialClaim);

        $financialClaim->update($request->validated());

        return $this->respond(new FinancialClaimResource(
            $financialClaim->fresh()->load(['project', 'customer']),
        ));
    }

    /**
     * Submit a claim
     *
     * Refused unless the operation is deliverable — Completed, or carrying at
     * least one active delivery. Claiming for work that has not been delivered
     * is how a receivable becomes a dispute, so the gate is here rather than
     * in anyone's judgement.
     *
     * @authenticated
     *
     * @urlParam financial_claim integer required The claim id. Example: 9
     *
     * @response 200 scenario="Submitted" {"data":{"id":9,"type":"financial_claim","status":{"value":"submitted","label":"Submitted","color":"warning"},"submitted_at":"2026-09-16T10:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Nothing delivered yet" {"error":{"code":"business_rule_violated","message":"A claim cannot be raised before supply or installation is complete."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function submit(FinancialClaim $financialClaim): JsonResponse
    {
        $this->authorize('submit', $financialClaim);

        $this->claims->submit($financialClaim);

        return $this->respond(new FinancialClaimResource(
            $financialClaim->fresh()->load(['project', 'customer']),
        ));
    }

    /**
     * Mark a claim collected
     *
     * Submitted claims only. A claim usually collects itself once the payments
     * allocated to it cover its amount, so this is mainly for cash that
     * arrived outside the platform.
     *
     * @authenticated
     *
     * @urlParam financial_claim integer required The claim id. Example: 9
     *
     * @response 200 scenario="Collected" {"data":{"id":9,"type":"financial_claim","status":{"value":"collected","label":"Collected","color":"success"},"collected_at":"2026-09-30T10:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function collect(FinancialClaim $financialClaim): JsonResponse
    {
        $this->authorize('collect', $financialClaim);

        $this->claims->collect($financialClaim);

        return $this->respond(new FinancialClaimResource(
            $financialClaim->fresh()->load(['project', 'customer']),
        ));
    }

    /**
     * Delete a draft claim
     *
     * Drafts only — the policy answers **403** afterwards.
     *
     * @authenticated
     *
     * @urlParam financial_claim integer required The claim id. Example: 9
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(FinancialClaim $financialClaim): JsonResponse
    {
        $this->authorize('delete', $financialClaim);

        $financialClaim->delete();

        return $this->respondNoContent();
    }
}
