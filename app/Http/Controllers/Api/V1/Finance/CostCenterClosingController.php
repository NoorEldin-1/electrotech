<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Finance\CloseCostCenterRequest;
use App\Http\Requests\Api\V1\Finance\ReverseCostCenterClosingRequest;
use App\Http\Resources\Api\V1\Finance\CostCenterClosingResource;
use App\Models\CostCenterClosing;
use App\Models\DeliveryVoucher;
use App\Models\Project;
use App\Services\CostCenterClosingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 36. Cost-centre closing
 *
 * إقفال مركز التكلفة — carrying an operation's accumulated cost out of
 * inventory and into cost of goods sold, once its goods have reached the
 * customer.
 *
 * There are two paths in, and they behave differently on purpose. The
 * **automatic** one fires when a delivery voucher activates: it is silent, it
 * never fires while a work order is still open on the operation (cost would be
 * charged to sales before it finished accruing), and it never throws — a
 * missing account must not roll back a delivery that physically happened.
 *
 * The **manual** one is this endpoint, and every refusal it makes is explicit,
 * so the user learns why nothing was posted rather than wondering: no active
 * delivery, nothing left to close, or the chart missing the COGS or inventory
 * account the entry needs.
 *
 * Undoing a closing is a **reversal**, never a deletion. The journal entry
 * behind it is posted and immutable, so the reversal writes a mirror-image
 * entry plus a negative closing row; the unclosed balance comes back on its
 * own and the audit trail stays whole.
 */
class CostCenterClosingController extends ApiController
{
    public function __construct(
        private readonly CostCenterClosingService $closings,
    ) {}

    /**
     * List cost-centre closings
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[delivery_voucher] integer Delivery voucher id. Example: 18
     * @queryParam filter[is_automatic] boolean Raised by a delivery rather than by hand. Example: true
     * @queryParam sort string Allowed: closed_at, amount, created_at. Example: -closed_at
     * @queryParam include string Allowed: project. Example: project
     *
     * @response 200 scenario="Success" {"data":[{"id":5,"type":"cost_center_closing","project_id":4,"amount":"430000.00","is_automatic":true,"is_reversed":false}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('operations.view_cost');

        $closings = ApiQuery::for(CostCenterClosing::query(), $request)
            ->allowFilters([
                'project' => ApiQuery::exact('project_id'),
                'delivery_voucher' => ApiQuery::exact('delivery_voucher_id'),
                'is_automatic' => ApiQuery::boolean('is_automatic'),
            ])
            ->allowSorts(['closed_at', 'amount', 'created_at'])
            ->allowIncludes(['project'])
            ->defaultSort('-closed_at')
            ->paginate();

        return $this->respondPaginated(CostCenterClosingResource::collection($closings));
    }

    /**
     * An operation's closing position
     *
     * What the operation consumed, what has already been closed, and what is
     * still sitting in inventory waiting to be. Plus the two conditions that
     * decide whether it *can* be closed: an active delivery, and no work order
     * still open.
     *
     * Call this before offering the close action — the same figures the close
     * endpoint would refuse on.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 4
     *
     * @response 200 scenario="Success" {"data":{"project_id":4,"inventory_consumed":"430000.00","closed_value":"0.00","unclosed_balance":"430000.00","is_closed":false,"is_partially_closed":false,"has_active_delivery":true,"has_open_work_orders":false,"can_close":true},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function status(Project $project): JsonResponse
    {
        $this->authorizePermission('operations.view_cost');

        $unclosed = $this->closings->unclosedBalance($project);
        $hasDelivery = $this->closings->hasActiveDelivery($project);
        $openOrders = $this->closings->hasOpenWorkOrders($project);

        return $this->respond([
            'project_id' => $project->id,
            'inventory_consumed' => number_format($this->closings->inventoryConsumed($project), 2, '.', ''),
            'closed_value' => number_format($this->closings->closedValue($project), 2, '.', ''),
            'unclosed_balance' => number_format($unclosed, 2, '.', ''),
            'is_closed' => $this->closings->isClosed($project),
            'is_partially_closed' => $this->closings->isPartiallyClosed($project),
            'has_active_delivery' => $hasDelivery,

            // The automatic path waits for this to be false; the manual one
            // does not, which is how finance closes an operation that still
            // has a stalled order on it.
            'has_open_work_orders' => $openOrders,

            'can_close' => $hasDelivery && $unclosed > 0.0,
        ]);
    }

    /**
     * Close an operation's cost centre
     *
     * Posts the journal entry that moves the unclosed balance from inventory to
     * cost of goods sold, and records the closing.
     *
     * The amount is **not** a parameter: what gets carried is the operation's
     * unclosed balance, computed from what it actually consumed. A figure typed
     * by hand would be a number nothing reconciles against.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 4
     *
     * @bodyParam delivery_voucher_id integer optional The delivery this closing belongs to, for the audit trail. Example: 18
     *
     * @response 201 scenario="Closed" {"data":{"id":5,"type":"cost_center_closing","project_id":4,"amount":"430000.00","is_automatic":false,"journal_entry_id":79},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Nothing delivered" {"error":{"code":"business_rule_violated","message":"Operation 2026-14 has no active delivery, so its cost centre cannot be closed."},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Nothing left to close" {"error":{"code":"business_rule_violated","message":"Operation 2026-14 has nothing left to close."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function close(CloseCostCenterRequest $request, Project $project): JsonResponse
    {
        $this->authorizePermission('operations.close_cost_center');

        $voucher = $request->filled('delivery_voucher_id')
            ? DeliveryVoucher::query()->find($request->integer('delivery_voucher_id'))
            : null;

        $closing = $this->closings->close($project, $voucher, $request->user());

        return $this->respondCreated(new CostCenterClosingResource($closing->load('project')));
    }

    /**
     * Reverse a closing
     *
     * Writes the mirror-image journal entry (Dr inventory / Cr COGS) and a
     * negative closing row linked to the original. Nothing is deleted, and the
     * operation's unclosed balance comes back on its own.
     *
     * Refused on a row that is itself a reversal, and on one that has already
     * been reversed — reversing a reversal twice would quietly double the
     * correction.
     *
     * @authenticated
     *
     * @urlParam cost_center_closing integer required The closing to undo. Example: 5
     *
     * @bodyParam reason string optional Why it is being undone. Example: Delivery voucher was cancelled by the customer
     *
     * @response 201 scenario="Reversed" {"data":{"id":6,"type":"cost_center_closing","amount":"-430000.00","is_reversal":true,"reverses_id":5},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Already reversed" {"error":{"code":"business_rule_violated","message":"This closing has already been reversed."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function reverse(ReverseCostCenterClosingRequest $request, CostCenterClosing $costCenterClosing): JsonResponse
    {
        $this->authorizePermission('operations.close_cost_center');

        $reversal = $this->closings->reverse(
            $costCenterClosing,
            $request->user(),
            $request->input('reason'),
        );

        return $this->respondCreated(new CostCenterClosingResource($reversal->load('project')));
    }
}
