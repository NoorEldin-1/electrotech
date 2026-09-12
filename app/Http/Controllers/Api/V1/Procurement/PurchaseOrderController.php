<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Procurement\ReceivePurchaseOrderRequest;
use App\Http\Requests\Api\V1\Procurement\ReplacePurchaseOrderItemsRequest;
use App\Http\Requests\Api\V1\Procurement\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\Procurement\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\Inventory\AdditionVoucherResource;
use App\Http\Resources\Api\V1\Procurement\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 15. Purchase orders
 *
 * Buying (أوامر الشراء): Draft → Submitted → Partially received → Received.
 *
 * Two things about this module surprise people reading it for the first time.
 *
 * **The money is derived.** `subtotal`, `vat_amount`, `profit_tax_amount` and
 * `total_amount` are all computed from the line items and the configured
 * rates; the client sends quantities and unit prices only. And the total is
 * `subtotal + VAT − profit tax` — the 1% profit-tax withholding is deducted,
 * and skipped entirely for an exempt supplier.
 *
 * **Receiving does not touch stock directly.** It raises an addition voucher
 * (إذن إضافة), the single goods-receipt document, and posts it. That voucher
 * adds the stock once, credits the supplier, and closes the order by comparing
 * ordered against received. Adding stock here as well would double-count every
 * delivery.
 */
class PurchaseOrderController extends ApiController
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    /**
     * List purchase orders
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the PO number or the supplier name. Example: PO-2026
     * @queryParam filter[status] string One or more purchase_order_status values, comma-separated. Example: submitted,partially_received
     * @queryParam filter[supplier] integer Supplier id. Example: 3
     * @queryParam filter[project] integer Operation id. Example: 1
     * @queryParam filter[warehouse_only] boolean Only orders with no operation — stock bought for the store. Example: true
     * @queryParam filter[created] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: po_number, status, total_amount, created_at, expected_delivery_date. Example: -created_at
     * @queryParam include string Allowed: supplier, project, approvedBy. Example: supplier
     * @queryParam updated_after string ISO-8601 timestamp for cache refresh. Example: 2026-08-01T10:00:00Z
     *
     * @response 200 scenario="Success" {"data":[{"id":4,"type":"purchase_order","po_number":"PO-202609-0007","status":{"value":"submitted","label":"Submitted","color":"info"},"subtotal":"100000.00","vat_amount":"14000.00","profit_tax_amount":"1000.00","total_amount":"113000.00","items_count":3}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $orders = ApiQuery::for(PurchaseOrder::query()->withCount('items'), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'supplier' => ApiQuery::exact('supplier_id'),
                'project' => ApiQuery::exact('project_id'),

                // An order with no operation is stock bought for the store
                // rather than against a job — a distinct list in the panel, so
                // a distinct filter here.
                'warehouse_only' => fn ($query, $value) => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? $query->whereNull('project_id')
                    : $query->whereNotNull('project_id'),

                'created' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSearch(['po_number', 'supplier_name'])
            ->allowSorts(['po_number', 'status', 'total_amount', 'created_at', 'expected_delivery_date'])
            ->allowIncludes(['supplier', 'project', 'approvedBy'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->respondPaginated(PurchaseOrderResource::collection($orders));
    }

    /**
     * Show a purchase order
     *
     * Carries every line with its ordered, received and remaining quantities —
     * `remaining_quantity` is the per-line cap the receive endpoint enforces.
     *
     * @authenticated
     *
     * @urlParam purchase_order integer required The purchase order id. Example: 4
     *
     * @response 200 scenario="Success" {"data":{"id":4,"type":"purchase_order","po_number":"PO-202609-0007","total_amount":"113000.00","items":[{"id":11,"item_id":7,"quantity":"100.0000","unit_price":"1000.00","received_quantity":"40.0000","remaining_quantity":"60.0000","is_fully_received":false}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return $this->respond(new PurchaseOrderResource(
            $purchaseOrder->load(['items.item', 'supplier', 'project', 'approvedBy']),
        ));
    }

    /**
     * Create a purchase order
     *
     * `po_number` is generated by the server (`PO-YYYYMM-NNNN`) and cannot be
     * supplied. The order is created as a **draft** with no lines and zero
     * totals; send the lines with `PUT /purchase-orders/{id}/items`.
     *
     * `project_id` is optional: leaving it out means stock bought for the
     * store rather than against one operation.
     *
     * @authenticated
     *
     * @bodyParam supplier_id integer required The supplier. Example: 3
     * @bodyParam project_id integer optional The operation this is bought for; omit for a warehouse purchase. Example: 1
     * @bodyParam apply_profit_tax boolean optional Whether to deduct the 1% profit-tax withholding. Defaults to true unless the supplier is exempt. Example: true
     * @bodyParam expected_delivery_date date optional Example: 2026-10-15
     * @bodyParam supplier_contact string optional Example: Sara Ali, +20 100 000 0000
     * @bodyParam notes string optional Example: Deliver to the Cairo store
     *
     * @response 201 scenario="Created" {"data":{"id":9,"type":"purchase_order","po_number":"PO-202609-0012","status":{"value":"draft","label":"Draft","color":"gray"},"total_amount":"0.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $data = $request->validated();
        $supplier = \App\Models\Supplier::find($data['supplier_id']);

        $order = PurchaseOrder::create($data + [
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => \App\Enums\PurchaseOrderStatus::Draft,
            'supplier_name' => $supplier?->name,

            // Default the withholding from the supplier's own exemption rather
            // than making the client remember it. An exempt supplier whose
            // order silently deducted 1% would be short-paid on every invoice.
            'apply_profit_tax' => $request->has('apply_profit_tax')
                ? $request->boolean('apply_profit_tax')
                : ! (bool) $supplier?->profit_tax_exempt,

            'created_by' => Auth::id(),
        ]);

        $order->recalculateTotal();

        return $this->respondCreated(new PurchaseOrderResource(
            $order->fresh()->load(['items.item', 'supplier', 'project']),
        ));
    }

    /**
     * Update a purchase order
     *
     * Only while it is a draft. Once approved the order has been sent to a
     * supplier, and once anything has been received the line quantities are
     * what the goods-receipt note was matched against.
     *
     * @authenticated
     *
     * @urlParam purchase_order integer required The purchase order id. Example: 4
     * @bodyParam supplier_id integer optional Example: 3
     * @bodyParam project_id integer optional Example: 1
     * @bodyParam apply_profit_tax boolean optional Example: false
     * @bodyParam expected_delivery_date date optional Example: 2026-10-20
     * @bodyParam supplier_contact string optional Example: Sara Ali
     * @bodyParam notes string optional Example: Revised delivery address
     *
     * @response 200 scenario="Success" {"data":{"id":4,"type":"purchase_order","notes":"Revised delivery address"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not a draft" {"error":{"code":"business_rule_violated","message":"A purchase order can only be edited while it is a draft."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $this->orders->assertEditable($purchaseOrder);

        $purchaseOrder->update($request->validated());

        // `apply_profit_tax` changes the total even though no line moved.
        $purchaseOrder->recalculateTotal();

        return $this->respond(new PurchaseOrderResource(
            $purchaseOrder->fresh()->load(['items.item', 'supplier', 'project']),
        ));
    }

    /**
     * Replace the line items
     *
     * Sends the whole order in one atomic request and re-derives the money.
     * Draft only. Same replace-not-merge reasoning as the BOQ and BOM
     * endpoints.
     *
     * @authenticated
     *
     * @urlParam purchase_order integer required The purchase order id. Example: 4
     * @bodyParam items object[] required The order lines. Send an empty array to clear them.
     * @bodyParam items[].item_id integer required An existing item id. Example: 7
     * @bodyParam items[].quantity number required Ordered quantity. Example: 100
     * @bodyParam items[].unit_price number required Agreed price per unit in EGP. Example: 1000
     *
     * @response 200 scenario="Success" {"data":{"id":4,"type":"purchase_order","subtotal":"100000.00","vat_amount":"14000.00","profit_tax_amount":"1000.00","total_amount":"113000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceItems(ReplacePurchaseOrderItemsRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);

        $this->orders->replaceItems($purchaseOrder, $request->array('items'));

        return $this->respond(new PurchaseOrderResource(
            $purchaseOrder->fresh()->load(['items.item', 'supplier', 'project']),
        ));
    }

    /**
     * Approve a purchase order
     *
     * Draft → Submitted, after which goods may be received against it.
     * Refused for an order with no supplier (nobody to send it to, nobody to
     * credit) or no lines (a receipt could never complete it).
     *
     * Gated by `purchase_orders.approve`, which is not `purchase_orders.edit`.
     *
     * @authenticated
     *
     * @urlParam purchase_order integer required The purchase order id. Example: 4
     *
     * @response 200 scenario="Success" {"data":{"id":4,"type":"purchase_order","status":{"value":"submitted","label":"Submitted","color":"info"},"approved_at":"2026-09-08T12:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="No line items" {"error":{"code":"business_rule_violated","message":"A purchase order needs at least one line item before it can be approved."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function approve(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('approve', $purchaseOrder);

        $this->orders->approve($purchaseOrder);

        return $this->respond(new PurchaseOrderResource(
            $purchaseOrder->fresh()->load(['items.item', 'supplier', 'approvedBy']),
        ));
    }

    /**
     * Receive goods against a purchase order
     *
     * Send the quantities that actually arrived, keyed by **purchase order
     * item id** — not item id, because one order may hold the same material on
     * two lines at two prices.
     *
     * This raises and posts an addition voucher (إذن إضافة). That single
     * document adds the stock, credits the supplier, and re-derives the order's
     * status by comparing ordered against received. The **voucher** is what
     * comes back, because its number is what the warehouse writes on the
     * paperwork.
     *
     * A quantity above a line's `remaining_quantity` is refused outright rather
     * than capped: over-receipt means the delivery note and the order disagree,
     * and silently accepting the smaller number would hide that.
     *
     * @authenticated
     *
     * @urlParam purchase_order integer required The purchase order id. Example: 4
     * @bodyParam items object required Received quantities keyed by purchase-order-item id. Example: {"11": 40}
     * @bodyParam invoice_number string optional The supplier invoice, if it arrived with the goods. Example: INV-88213
     *
     * @response 201 scenario="Received" {"data":{"id":15,"type":"addition_voucher","voucher_number":"AV-202609-0031","status":{"value":"posted","label":"Posted","color":"success"},"purchase_order_id":4,"lines":[{"item_id":7,"quantity":"40.0000","unit_cost":"1000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="More than ordered" {"error":{"code":"business_rule_violated","message":"Cannot receive 200 of 'Copper Busbar': ordered 100, already received 40."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        // The panel gates receiving on the bare permission and has no policy
        // method for it, so the API checks the same string.
        $this->authorizePermission('purchase_orders.receive');

        $voucher = $this->orders->receiveItems(
            purchaseOrder: $purchaseOrder,
            receivedQuantities: $request->array('items'),
            invoiceNumber: $request->input('invoice_number'),
        );

        return $this->respondCreated(new AdditionVoucherResource(
            $voucher->load(['lines.item', 'supplier', 'purchaseOrder']),
        ));
    }

    /**
     * Delete a purchase order
     *
     * Soft delete, draft only.
     *
     * @authenticated
     *
     * @urlParam purchase_order integer required The purchase order id. Example: 4
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('delete', $purchaseOrder);
        $this->orders->assertEditable($purchaseOrder);

        $purchaseOrder->delete();

        return $this->respondNoContent();
    }
}
