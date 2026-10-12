<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Sales\StoreCustomerInteractionRequest;
use App\Http\Requests\Api\V1\Sales\UpdateCustomerInteractionRequest;
use App\Http\Resources\Api\V1\Sales\CustomerInteractionResource;
use App\Models\CustomerInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 7. Customer follow-up
 *
 * متابعة العملاء — قسم إدارة العملاء (Presentation.pptx slides 6–7). The log
 * of touchpoints with the customer on each operation.
 *
 * Two entries move the operation timeline: the first `contact` reaches
 * "Customer Contact", and the first `site_readiness` with `site_ready: true`
 * reaches "Site Readiness". `follow_up` is a plain log entry. The customer is
 * always the operation's customer — it is never sent.
 */
class CustomerInteractionController extends ApiController
{
    /**
     * List customer interactions
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the notes. Example: drawings
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[customer] integer Customer id. Example: 2
     * @queryParam filter[type] string contact, site_readiness or follow_up. Example: contact
     * @queryParam filter[interacted_at] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: interacted_at, created_at. Example: -interacted_at
     * @queryParam include string Allowed: project. Example: project
     *
     * @response 200 scenario="Success" {"data":[{"id":5,"type":"customer_interaction","project_id":4,"customer_id":2,"interaction_type":{"value":"contact","label":"Customer Contact","color":"info"},"channel":{"value":"call","label":"Phone Call","color":null},"interacted_at":"2026-09-01T10:00:00+00:00","site_ready":false,"notes":"First call"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerInteraction::class);

        $interactions = ApiQuery::for(CustomerInteraction::query(), $request)
            ->allowFilters([
                'project' => ApiQuery::exact('project_id'),
                'customer' => ApiQuery::exact('customer_id'),
                'type' => ApiQuery::exact('type'),
                'interacted_at' => ApiQuery::dateBetween('interacted_at'),
            ])
            ->allowSearch(['notes'])
            ->allowSorts(['interacted_at', 'created_at'])
            ->allowIncludes(['project'])
            ->defaultSort('-interacted_at')
            ->paginate();

        return $this->respondPaginated(CustomerInteractionResource::collection($interactions));
    }

    /**
     * Show a customer interaction
     *
     * @authenticated
     *
     * @urlParam customer_interaction integer required The interaction id. Example: 5
     *
     * @response 200 scenario="Success" {"data":{"id":5,"type":"customer_interaction","project_id":4,"interaction_type":{"value":"site_readiness","label":"Site Readiness","color":"success"},"site_ready":true},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(CustomerInteraction $customerInteraction): JsonResponse
    {
        $this->authorize('view', $customerInteraction);

        return $this->respond(new CustomerInteractionResource($customerInteraction->load('project')));
    }

    /**
     * Log a customer interaction
     *
     * `interacted_at` defaults to now. `site_ready` only sticks on a
     * `site_readiness` entry.
     *
     * @authenticated
     *
     * @bodyParam project_id integer required The operation. Example: 4
     * @bodyParam type string required contact, site_readiness or follow_up. Example: contact
     * @bodyParam channel string optional call, visit, whatsapp or email. Example: call
     * @bodyParam interacted_at datetime optional Defaults to now. Example: 2026-09-01 10:00:00
     * @bodyParam site_ready boolean optional Only for site_readiness. Example: true
     * @bodyParam notes string optional Example: Customer confirmed the site is ready
     *
     * @response 201 scenario="Created" {"data":{"id":5,"type":"customer_interaction","project_id":4,"interaction_type":{"value":"contact","label":"Customer Contact","color":"info"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreCustomerInteractionRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerInteraction::class);

        $interaction = CustomerInteraction::create([
            'project_id' => $request->integer('project_id'),
            'type' => $request->input('type'),
            'channel' => $request->input('channel'),
            'interacted_at' => $request->input('interacted_at', now()),
            'site_ready' => $request->boolean('site_ready'),
            'notes' => $request->input('notes'),
        ]);

        return $this->respondCreated(new CustomerInteractionResource($interaction->load('project')));
    }

    /**
     * Update a customer interaction
     *
     * @authenticated
     *
     * @urlParam customer_interaction integer required The interaction id. Example: 5
     *
     * @bodyParam type string optional Example: site_readiness
     * @bodyParam site_ready boolean optional Example: true
     * @bodyParam notes string optional Example: Second call — site ready
     *
     * @response 200 scenario="Updated" {"data":{"id":5,"type":"customer_interaction","site_ready":true},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateCustomerInteractionRequest $request, CustomerInteraction $customerInteraction): JsonResponse
    {
        $this->authorize('update', $customerInteraction);

        $customerInteraction->update($request->validated());

        return $this->respond(new CustomerInteractionResource($customerInteraction->fresh()->load('project')));
    }

    /**
     * Delete a customer interaction
     *
     * The timeline stage it proved goes back to "not reached" unless another
     * entry still proves it.
     *
     * @authenticated
     *
     * @urlParam customer_interaction integer required The interaction id. Example: 5
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(CustomerInteraction $customerInteraction): JsonResponse
    {
        $this->authorize('delete', $customerInteraction);

        $customerInteraction->delete();

        return $this->respondNoContent();
    }
}
