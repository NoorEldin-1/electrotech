<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\StoreCustomerRequest;
use App\Http\Requests\Api\V1\MasterData\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\MasterData\CustomerResource;
use App\Models\Customer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 8. Customers
 *
 * The customer file (العملاء). A customer owns projects and delivery
 * vouchers, and carries a running ledger balance built from the account
 * entries those documents post.
 */
class CustomerController extends ApiController
{
    /**
     * List customers
     *
     * The list omits `balance` on purpose: it is a SUM over `account_entries`
     * per row, so returning it for a page of 25 would mean 25 aggregate
     * queries for a number the list view does not show. Fetch the detail
     * endpoint for a customer whose balance you need.
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches name, contact person, phone or email. Example: delta
     * @queryParam filter[created] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: name, created_at. Example: name
     * @queryParam updated_after string ISO-8601 timestamp for cache refresh. Example: 2026-08-01T10:00:00Z
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"type":"customer","name":"Delta Contracting","contact_person":"Ahmed Hassan","phone":"+20 100 000 0000","email":"info@example.com","projects_count":3}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $customers = ApiQuery::for(Customer::query()->withCount('projects'), $request)
            ->allowFilters([
                'created' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSearch(['name', 'contact_person', 'phone', 'email'])
            ->allowSorts(['name', 'created_at'])
            ->defaultSort('name')
            ->paginate();

        return $this->respondPaginated(CustomerResource::collection($customers));
    }

    /**
     * Show a customer
     *
     * Includes the running ledger balance. Positive means the customer owes
     * us; negative means they are in credit.
     *
     * @authenticated
     *
     * @urlParam customer integer required The customer id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"customer","name":"Delta Contracting","balance":"125400.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return $this->respond(
            (new CustomerResource($customer->loadCount('projects')))->withBalance(),
        );
    }

    /**
     * Create a customer
     *
     * @authenticated
     *
     * @bodyParam name string required Company or person name. Example: Delta Contracting
     * @bodyParam contact_person string optional Example: Ahmed Hassan
     * @bodyParam phone string optional Example: +20 100 000 0000
     * @bodyParam email string optional Example: info@example.com
     * @bodyParam tax_number string optional Egyptian tax registration number. Example: 123-456-789
     * @bodyParam address string optional Example: 12 Nile St, Cairo
     * @bodyParam notes string optional Example: Pays on 60-day terms
     *
     * @response 201 scenario="Created" {"data":{"id":4,"type":"customer","name":"Delta Contracting"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::create($request->validated());

        return $this->respondCreated(new CustomerResource($customer));
    }

    /**
     * Update a customer
     *
     * @authenticated
     *
     * @urlParam customer integer required The customer id. Example: 1
     * @bodyParam name string optional Example: Delta Contracting Co.
     * @bodyParam contact_person string optional Example: Mona Said
     * @bodyParam phone string optional Example: +20 100 000 0001
     * @bodyParam email string optional Example: accounts@example.com
     * @bodyParam tax_number string optional Example: 123-456-789
     * @bodyParam address string optional Example: 12 Nile St, Cairo
     * @bodyParam notes string optional Example: Pays on 60-day terms
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"customer","name":"Delta Contracting Co."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->update($request->validated());

        return $this->respond(
            (new CustomerResource($customer->fresh()))->withBalance(),
        );
    }

    /**
     * Delete a customer
     *
     * Soft delete. Refused while the customer still owns projects: those rows
     * render the customer name, and removing it would leave a live operation
     * pointing at a record the API no longer returns.
     *
     * @authenticated
     *
     * @urlParam customer integer required The customer id. Example: 1
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Has projects" {"error":{"code":"business_rule_violated","message":"Cannot delete a customer that still has projects."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        if ($customer->projects()->exists()) {
            throw new DomainException(__('errors.api.customer_has_projects'));
        }

        $customer->delete();

        return $this->respondNoContent();
    }
}
