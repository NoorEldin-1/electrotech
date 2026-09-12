<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Finance\StoreSalesInvoiceRequest;
use App\Http\Resources\Api\V1\Finance\SalesInvoiceResource;
use App\Models\DeliveryVoucher;
use App\Models\SalesInvoice;
use App\Services\SalesInvoicingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 31. Sales invoices
 *
 * فواتير المبيعات — invoicing what was delivered.
 *
 * An invoice is always raised **against a delivery voucher that is active**,
 * which is to say against goods that actually left the store. Its customer is
 * taken from that voucher rather than sent, so an invoice can never name
 * someone other than whoever received the goods.
 *
 * A voucher may be invoiced in instalments, but the instalments may not add up
 * to more than was delivered. That single rule is what keeps "delivered" and
 * "invoiced" reconcilable, and it is what drives the voucher's own
 * `invoicing.status`, which is **re-derived** on every change — record an
 * invoice or delete one to move it, never write the field.
 */
class SalesInvoiceController extends ApiController
{
    public function __construct(
        private readonly SalesInvoicingService $invoicing,
    ) {}

    /**
     * List sales invoices
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the invoice number. Example: INV-2026
     * @queryParam filter[customer] integer Customer id. Example: 7
     * @queryParam filter[delivery_voucher] integer Delivery voucher id. Example: 18
     * @queryParam filter[invoice_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: invoice_number, invoice_date, amount, created_at. Example: -invoice_date
     * @queryParam include string Allowed: customer, deliveryVoucher. Example: customer
     *
     * @response 200 scenario="Success" {"data":[{"id":22,"type":"sales_invoice","invoice_number":"INV-2026-0044","amount":"150000.00","delivery_voucher_id":18}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalesInvoice::class);

        $invoices = ApiQuery::for(SalesInvoice::query(), $request)
            ->allowFilters([
                'customer' => ApiQuery::exact('customer_id'),
                'delivery_voucher' => ApiQuery::exact('delivery_voucher_id'),
                'invoice_date' => ApiQuery::dateBetween('invoice_date'),
            ])
            ->allowSearch(['invoice_number'])
            ->allowSorts(['invoice_number', 'invoice_date', 'amount', 'created_at'])
            ->allowIncludes(['customer', 'deliveryVoucher'])
            ->defaultSort('-invoice_date')
            ->paginate();

        return $this->respondPaginated(SalesInvoiceResource::collection($invoices));
    }

    /**
     * Show a sales invoice
     *
     * @authenticated
     *
     * @urlParam sales_invoice integer required The invoice id. Example: 22
     *
     * @response 200 scenario="Success" {"data":{"id":22,"type":"sales_invoice","invoice_number":"INV-2026-0044","amount":"150000.00","delivery_voucher":{"total_value":"250000.00","invoiced_value":"150000.00"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorize('view', $salesInvoice);

        return $this->respond(new SalesInvoiceResource(
            $salesInvoice->load(['customer', 'deliveryVoucher']),
        ));
    }

    /**
     * Invoice a delivery
     *
     * Refused unless the voucher is **active** — an invoice for goods that
     * have not been delivered is a dispute waiting to happen — and refused
     * when the amount would take the voucher past its delivered value.
     *
     * The refusal names the remaining amount, so the message can be shown as
     * written and the user can correct the figure without a second call.
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The delivery being invoiced. Example: 18
     *
     * @bodyParam invoice_number string required The invoice's own number. Example: INV-2026-0044
     * @bodyParam invoice_date date required Example: 2026-09-15
     * @bodyParam amount number required May be part of the delivered value; instalments are fine. Example: 150000
     * @bodyParam notes string optional Example: First instalment, 60%
     *
     * @response 201 scenario="Created" {"data":{"id":22,"type":"sales_invoice","invoice_number":"INV-2026-0044","amount":"150000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Exceeds the delivery" {"error":{"code":"business_rule_violated","message":"Invoicing DV-202609-0005 for more than was delivered. Remaining: 100,000.00."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreSalesInvoiceRequest $request, DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('create', SalesInvoice::class);

        $invoice = $this->invoicing->record(
            $deliveryVoucher,
            $request->validated(),
            $request->user(),
        );

        return $this->respondCreated(new SalesInvoiceResource(
            $invoice->load(['customer', 'deliveryVoucher']),
        ));
    }

    /**
     * Delete a sales invoice
     *
     * The voucher's invoiced value and invoicing status are re-derived
     * immediately afterwards, so deleting the only invoice on a voucher puts
     * it back to `not_invoiced` rather than leaving a stale status behind.
     *
     * @authenticated
     *
     * @urlParam sales_invoice integer required The invoice id. Example: 22
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorize('delete', $salesInvoice);

        $voucher = $salesInvoice->deliveryVoucher;

        $salesInvoice->delete();

        if ($voucher !== null) {
            $this->invoicing->recalculate($voucher->fresh());
        }

        return $this->respondNoContent();
    }
}
