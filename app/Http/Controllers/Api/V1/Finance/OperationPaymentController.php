<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Finance\StoreOperationPaymentRequest;
use App\Http\Resources\Api\V1\Finance\OperationPaymentResource;
use App\Models\FinancialClaim;
use App\Models\OperationPayment;
use App\Models\Project;
use App\Services\OperationPaymentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 32. Operation payments
 *
 * الدفعات والمقبوضات — cash moving in or out against an operation.
 *
 * Recording a payment can do three things at once, depending on configuration
 * and on what the payload carries:
 *
 *  1. it writes the payment;
 *  2. when automatic journalling is on and both accounts resolve, it writes
 *     **and posts** a balanced journal entry, linking it back;
 *  3. when the payment names a financial claim, it allocates to it, and the
 *     claim collects itself once it is fully paid.
 *
 * Step 2 is what freezes the payment. Once `journal_entry_id` is set the
 * policy refuses edits and deletes: the money is in the ledger, and the
 * correction for a posted entry is another entry, not a quiet edit of the
 * first.
 */
class OperationPaymentController extends ApiController
{
    public function __construct(
        private readonly OperationPaymentService $payments,
    ) {}

    /**
     * List operation payments
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the payment number or reference. Example: PMT-2026
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[customer] integer Customer id. Example: 7
     * @queryParam filter[direction] string incoming or outgoing. Example: incoming
     * @queryParam filter[method] string cash, cheque or bank_transfer. Example: bank_transfer
     * @queryParam filter[financial_claim] integer Claim id. Example: 9
     * @queryParam filter[payment_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: payment_number, payment_date, amount, created_at. Example: -payment_date
     * @queryParam include string Allowed: project, customer. Example: project
     *
     * @response 200 scenario="Success" {"data":[{"id":30,"type":"operation_payment","payment_number":"PMT-202609-0008","direction":{"value":"incoming","label":"Incoming","color":"success"},"amount":"150000.00","posted_to_ledger":true}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OperationPayment::class);

        $payments = ApiQuery::for(OperationPayment::query(), $request)
            ->allowFilters([
                'project' => ApiQuery::exact('project_id'),
                'customer' => ApiQuery::exact('customer_id'),
                'direction' => ApiQuery::exact('direction'),
                'method' => ApiQuery::exact('method'),
                'financial_claim' => ApiQuery::exact('financial_claim_id'),
                'payment_date' => ApiQuery::dateBetween('payment_date'),
            ])
            ->allowSearch(['payment_number', 'reference'])
            ->allowSorts(['payment_number', 'payment_date', 'amount', 'created_at'])
            ->allowIncludes(['project', 'customer'])
            ->defaultSort('-payment_date')
            ->paginate();

        return $this->respondPaginated(OperationPaymentResource::collection($payments));
    }

    /**
     * Show a payment
     *
     * @authenticated
     *
     * @urlParam operation_payment integer required The payment id. Example: 30
     *
     * @response 200 scenario="Success" {"data":{"id":30,"type":"operation_payment","payment_number":"PMT-202609-0008","amount":"150000.00","journal_entry_id":78,"posted_to_ledger":true},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(OperationPayment $operationPayment): JsonResponse
    {
        $this->authorize('view', $operationPayment);

        return $this->respond(new OperationPaymentResource(
            $operationPayment->load(['project', 'customer']),
        ));
    }

    /**
     * Record a payment
     *
     * Send `account_id` and `counter_account_id` when the payment should reach
     * the general ledger — without them there is nothing to post, and the
     * payment stays a record with no entry behind it.
     *
     * @authenticated
     *
     * @bodyParam project_id integer required The operation the cash belongs to. Example: 4
     * @bodyParam customer_id integer optional Example: 7
     * @bodyParam financial_claim_id integer optional Allocate to a claim; it collects itself once fully paid. Example: 9
     * @bodyParam direction string required incoming or outgoing. Example: incoming
     * @bodyParam method string required cash, cheque or bank_transfer. Example: bank_transfer
     * @bodyParam account_id integer optional The cash/bank side. Example: 1100
     * @bodyParam counter_account_id integer optional The other side. Example: 1200
     * @bodyParam amount number required Example: 150000
     * @bodyParam currency string optional Three letters, defaults to EGP. Example: EGP
     * @bodyParam payment_date date optional Defaults to today. Example: 2026-09-16
     * @bodyParam reference string optional Cheque or transfer reference. Example: CHQ-99213
     * @bodyParam notes string optional Example: First instalment
     *
     * @response 201 scenario="Created" {"data":{"id":30,"type":"operation_payment","payment_number":"PMT-202609-0008","amount":"150000.00","posted_to_ledger":true},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreOperationPaymentRequest $request): JsonResponse
    {
        $this->authorize('create', OperationPayment::class);

        $payment = $this->payments->record($request->validated());

        return $this->respondCreated(new OperationPaymentResource(
            $payment->load(['project', 'customer']),
        ));
    }

    /**
     * Allocate a payment to a claim
     *
     * Links an existing payment to a financial claim and collects the claim if
     * the payments against it now cover its amount. Useful when the cash
     * arrived first and the claim it settles was identified afterwards.
     *
     * @authenticated
     *
     * @urlParam operation_payment integer required The payment id. Example: 30
     * @urlParam financial_claim integer required The claim to settle. Example: 9
     *
     * @response 200 scenario="Allocated" {"data":{"id":30,"type":"operation_payment","financial_claim_id":9},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function allocate(OperationPayment $operationPayment, FinancialClaim $financialClaim): JsonResponse
    {
        $this->authorize('update', $operationPayment);

        $this->payments->allocateToClaim($operationPayment, $financialClaim);

        return $this->respond(new OperationPaymentResource(
            $operationPayment->fresh()->load(['project', 'customer']),
        ));
    }

    /**
     * Cash totals for an operation
     *
     * What has been received, what has been paid out, and the net. These are
     * the figures the operation's cost file reads as collected revenue, so
     * they are published rather than left to each client to sum — two screens
     * summing the same rows differently is how a cash position becomes an
     * argument.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 4
     *
     * @response 200 scenario="Success" {"data":{"project_id":4,"received":"150000.00","paid":"20000.00","net":"130000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function totalsForProject(Project $project): JsonResponse
    {
        $this->authorize('viewAny', OperationPayment::class);

        $totals = $this->payments->totalsForProject($project);

        return $this->respond([
            'project_id' => $project->id,
            'received' => number_format($totals['received'], 2, '.', ''),
            'paid' => number_format($totals['paid'], 2, '.', ''),
            'net' => number_format($totals['net'], 2, '.', ''),
        ]);
    }

    /**
     * Delete a payment
     *
     * Only while it has not reached the ledger. Once a journal entry is linked
     * the policy answers **403**: the entry is posted and immutable, so a
     * deleted payment would leave it pointing at nothing.
     *
     * @authenticated
     *
     * @urlParam operation_payment integer required The payment id. Example: 30
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(OperationPayment $operationPayment): JsonResponse
    {
        $this->authorize('delete', $operationPayment);

        if ($operationPayment->journal_entry_id !== null) {
            throw new DomainException(__('errors.api.payment_has_journal'));
        }

        $operationPayment->delete();

        return $this->respondNoContent();
    }
}
