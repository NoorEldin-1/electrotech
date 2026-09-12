<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\StoreSupplierRequest;
use App\Http\Requests\Api\V1\MasterData\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\MasterData\SupplierResource;
use App\Models\Supplier;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 9. Suppliers
 *
 * The supplier file (الموردين). A supplier owns purchase orders and addition
 * vouchers, and carries a running ledger balance from the account entries
 * those documents post.
 */
class SupplierController extends ApiController
{
    /**
     * List suppliers
     *
     * `balance` is omitted here for the same reason as on customers: it is a
     * per-row aggregate over `account_entries`. Use the detail endpoint.
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches name, contact person, phone or email. Example: metals
     * @queryParam filter[profit_tax_exempt] boolean Only suppliers holding a 1% withholding exemption. Example: true
     * @queryParam filter[created] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: name, created_at. Example: name
     * @queryParam updated_after string ISO-8601 timestamp for cache refresh. Example: 2026-08-01T10:00:00Z
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"type":"supplier","name":"Cairo Metals","profit_tax_exempt":false,"purchase_orders_count":7}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = ApiQuery::for(Supplier::query()->withCount('purchaseOrders'), $request)
            ->allowFilters([
                'profit_tax_exempt' => ApiQuery::boolean('profit_tax_exempt'),
                'created' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSearch(['name', 'contact_person', 'phone', 'email'])
            ->allowSorts(['name', 'created_at'])
            ->defaultSort('name')
            ->paginate();

        return $this->respondPaginated(SupplierResource::collection($suppliers));
    }

    /**
     * Show a supplier
     *
     * Includes the running ledger balance. Positive means we owe the supplier.
     *
     * @authenticated
     *
     * @urlParam supplier integer required The supplier id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"supplier","name":"Cairo Metals","profit_tax_exempt":false,"balance":"48200.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return $this->respond(
            (new SupplierResource($supplier->loadCount('purchaseOrders')))->withBalance(),
        );
    }

    /**
     * Create a supplier
     *
     * @authenticated
     *
     * @bodyParam name string required Company name. Example: Cairo Metals
     * @bodyParam contact_person string optional Example: Sara Ali
     * @bodyParam phone string optional Example: +20 100 000 0000
     * @bodyParam email string optional Example: sales@example.com
     * @bodyParam tax_number string optional Egyptian tax registration number. Example: 123-456-789
     * @bodyParam profit_tax_exempt boolean optional True when the supplier holds an exemption from the 1% profit-tax withholding, which changes every purchase-order total. Defaults to false. Example: false
     * @bodyParam address string optional Example: 8 Industrial Zone, Cairo
     * @bodyParam notes string optional Example: Lead time 3 weeks
     *
     * @response 201 scenario="Created" {"data":{"id":4,"type":"supplier","name":"Cairo Metals","profit_tax_exempt":false},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = Supplier::create($request->validated());

        return $this->respondCreated(new SupplierResource($supplier));
    }

    /**
     * Update a supplier
     *
     * @authenticated
     *
     * @urlParam supplier integer required The supplier id. Example: 1
     * @bodyParam name string optional Example: Cairo Metals Co.
     * @bodyParam contact_person string optional Example: Sara Ali
     * @bodyParam phone string optional Example: +20 100 000 0001
     * @bodyParam email string optional Example: sales@example.com
     * @bodyParam tax_number string optional Example: 123-456-789
     * @bodyParam profit_tax_exempt boolean optional Example: true
     * @bodyParam address string optional Example: 8 Industrial Zone, Cairo
     * @bodyParam notes string optional Example: Lead time 3 weeks
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"supplier","name":"Cairo Metals Co."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('update', $supplier);

        $supplier->update($request->validated());

        return $this->respond(
            (new SupplierResource($supplier->fresh()))->withBalance(),
        );
    }

    /**
     * Delete a supplier
     *
     * Soft delete. Refused while purchase orders still reference the supplier.
     *
     * @authenticated
     *
     * @urlParam supplier integer required The supplier id. Example: 1
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Has purchase orders" {"error":{"code":"business_rule_violated","message":"Cannot delete a supplier that still has purchase orders."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorize('delete', $supplier);

        if ($supplier->purchaseOrders()->exists()) {
            throw new DomainException(__('errors.api.supplier_has_purchase_orders'));
        }

        $supplier->delete();

        return $this->respondNoContent();
    }
}
