<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Enums\VoucherStatus;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Inventory\CloseAdditionVoucherRequest;
use App\Http\Requests\Api\V1\Inventory\RecordInvoiceRequest;
use App\Http\Requests\Api\V1\Inventory\StoreAdditionVoucherRequest;
use App\Http\Resources\Api\V1\Inventory\AdditionVoucherResource;
use App\Models\AdditionVoucher;
use App\Services\AdditionVoucherService;
use App\Services\PurchaseInvoicingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group 18. Addition vouchers
 *
 * Goods receipt (إذن إضافة). This is the **only** document that adds purchased
 * stock, which is why receiving against a purchase order produces one rather
 * than touching the balance directly.
 *
 * The lifecycle is Draft → Posted, and posting is the moment everything
 * happens at once: the stock is added, the supplier is credited, and any linked
 * purchase order has its received quantities updated and its status re-derived.
 * A posted voucher is immutable — the correction for a mistake is a separate
 * document, not an edit, because the stock and the ledger have already moved.
 *
 * After posting, the **invoicing** state takes over: `not_invoiced` until the
 * supplier's invoice arrives, then `invoiced`, or `closed_uninvoiced` when it
 * is decided the invoice never will. That field is derived on every save —
 * record an invoice or close the voucher to change it, never write it.
 */
class AdditionVoucherController extends ApiController
{
    public function __construct(
        private readonly AdditionVoucherService $vouchers,
        private readonly PurchaseInvoicingService $invoicing,
    ) {}

    /**
     * List addition vouchers
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the voucher number, supplier name or invoice number. Example: AV-2026
     * @queryParam filter[status] string draft or posted. Example: posted
     * @queryParam filter[invoicing_status] string One or more purchase_invoicing_status values. Example: not_invoiced
     * @queryParam filter[supplier] integer Supplier id. Example: 3
     * @queryParam filter[purchase_order] integer Purchase order id. Example: 4
     * @queryParam filter[voucher_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: voucher_number, voucher_date, posted_at, created_at. Example: -voucher_date
     * @queryParam include string Allowed: supplier, purchaseOrder. Example: supplier
     *
     * @response 200 scenario="Success" {"data":[{"id":15,"type":"addition_voucher","voucher_number":"AV-202609-0031","status":{"value":"posted","label":"Posted","color":"success"},"invoicing_status":{"value":"not_invoiced","label":"Not Invoiced","color":"warning"},"received_value":"40000.00","lines_count":1}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AdditionVoucher::class);

        $vouchers = ApiQuery::for(AdditionVoucher::query()->withCount('lines'), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'invoicing_status' => ApiQuery::exact('invoicing_status'),
                'supplier' => ApiQuery::exact('supplier_id'),
                'purchase_order' => ApiQuery::exact('purchase_order_id'),
                'voucher_date' => ApiQuery::dateBetween('voucher_date'),
            ])
            ->allowSearch(['voucher_number', 'supplier_name', 'invoice_number'])
            ->allowSorts(['voucher_number', 'voucher_date', 'posted_at', 'created_at'])
            ->allowIncludes(['supplier', 'purchaseOrder'])
            ->defaultSort('-voucher_date')
            ->paginate();

        return $this->respondPaginated(AdditionVoucherResource::collection($vouchers));
    }

    /**
     * Show an addition voucher
     *
     * @authenticated
     *
     * @urlParam addition_voucher integer required The voucher id. Example: 15
     *
     * @response 200 scenario="Success" {"data":{"id":15,"type":"addition_voucher","voucher_number":"AV-202609-0031","status":{"value":"posted","label":"Posted","color":"success"},"lines":[{"item_id":7,"quantity":"40.0000","unit_cost":"1000.00","line_value":"40000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(AdditionVoucher $additionVoucher): JsonResponse
    {
        $this->authorize('view', $additionVoucher);

        return $this->respond(new AdditionVoucherResource(
            $additionVoucher->load(['lines.item', 'supplier', 'purchaseOrder']),
        ));
    }

    /**
     * Create a draft addition voucher
     *
     * For goods arriving **without** a purchase order. Receiving against an
     * order goes through `POST /purchase-orders/{id}/receive` instead, which
     * builds and posts the voucher for you and enforces the ordered quantities.
     *
     * `voucher_number` is generated by the server. The lines are sent with the
     * voucher; nothing moves until it is posted.
     *
     * A registered `supplier_id` is optional — a receipt with no invoice and no
     * order may carry only a free-text `supplier_name`. Note the consequence:
     * with no supplier record there is nobody to credit, so posting adds the
     * stock and writes no ledger entry.
     *
     * @authenticated
     *
     * @bodyParam supplier_id integer optional The supplier to credit on posting. Example: 3
     * @bodyParam supplier_name string optional Free-text name when there is no supplier record. Example: Walk-in supplier
     * @bodyParam voucher_date date optional Defaults to today. Example: 2026-09-08
     * @bodyParam invoice_number string optional If the invoice arrived with the goods. Example: INV-88213
     * @bodyParam invoice_value number optional The invoice total; falls back to the stock value when omitted. Example: 40000
     * @bodyParam notes string optional Example: Received at the Cairo store
     * @bodyParam lines object[] required What arrived.
     * @bodyParam lines[].item_id integer required Example: 7
     * @bodyParam lines[].quantity number required Example: 40
     * @bodyParam lines[].unit_cost number required Cost per unit in EGP. Example: 1000
     *
     * @response 201 scenario="Created" {"data":{"id":16,"type":"addition_voucher","voucher_number":"AV-202609-0032","status":{"value":"draft","label":"Draft","color":"gray"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreAdditionVoucherRequest $request): JsonResponse
    {
        $this->authorize('create', AdditionVoucher::class);

        $voucher = DB::transaction(function () use ($request): AdditionVoucher {
            $voucher = AdditionVoucher::create([
                'voucher_number' => AdditionVoucher::generateVoucherNumber(),
                'supplier_id' => $request->input('supplier_id'),
                'supplier_name' => $request->input('supplier_name'),
                'voucher_date' => $request->input('voucher_date', now()->toDateString()),
                'invoice_number' => $request->input('invoice_number'),
                'invoice_value' => $request->input('invoice_value'),
                'notes' => $request->input('notes'),
                'status' => VoucherStatus::Draft,
                'received_by' => Auth::id(),
            ]);

            foreach ($request->array('lines') as $line) {
                $voucher->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ]);
            }

            return $voucher;
        });

        return $this->respondCreated(new AdditionVoucherResource(
            $voucher->load(['lines.item', 'supplier']),
        ));
    }

    /**
     * Post an addition voucher
     *
     * The moment everything happens: each line is added to its warehouse, the
     * supplier is credited, and a linked purchase order has its received
     * quantities updated and its status re-derived. All in one transaction.
     *
     * Gated by `addition_vouchers.post`, which is not
     * `addition_vouchers.create` — raising a receipt and committing it to the
     * stock ledger and the supplier's account are different acts.
     *
     * Posting twice is refused, not ignored. That is what makes double-posting
     * impossible even if the `Idempotency-Key` is fumbled.
     *
     * @authenticated
     *
     * @urlParam addition_voucher integer required The voucher id. Example: 16
     *
     * @response 200 scenario="Posted" {"data":{"id":16,"type":"addition_voucher","status":{"value":"posted","label":"Posted","color":"success"},"posted_at":"2026-09-08T12:45:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Already posted" {"error":{"code":"business_rule_violated","message":"Voucher AV-202609-0032 is already posted."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function post(AdditionVoucher $additionVoucher): JsonResponse
    {
        $this->authorize('post', $additionVoucher);

        $this->vouchers->post($additionVoucher);

        return $this->respond(new AdditionVoucherResource(
            $additionVoucher->fresh()->load(['lines.item', 'supplier', 'purchaseOrder']),
        ));
    }

    /**
     * Record the supplier invoice
     *
     * The supplier was credited at posting time with the stock value, because
     * the invoice had not arrived. This **corrects** that entry to the real
     * figure rather than adding a second one — a new entry would double the
     * supplier's balance.
     *
     * An invoice also reopens a voucher that had been closed as never-to-be-
     * invoiced: the file said the invoice would never come, and it was wrong.
     *
     * @authenticated
     *
     * @urlParam addition_voucher integer required The voucher id. Example: 16
     * @bodyParam invoice_number string required The supplier's invoice number. Example: INV-88213
     * @bodyParam invoice_date date optional Example: 2026-09-10
     * @bodyParam invoice_value number optional The invoice total; keeps the existing value when omitted. Example: 40250
     *
     * @response 200 scenario="Recorded" {"data":{"id":16,"type":"addition_voucher","invoicing_status":{"value":"invoiced","label":"Invoiced","color":"success"},"invoice_number":"INV-88213","invoice_value":"40250.00","invoice_value_mismatch":"250.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function recordInvoice(RecordInvoiceRequest $request, AdditionVoucher $additionVoucher): JsonResponse
    {
        $this->authorize('invoice', $additionVoucher);

        $this->invoicing->recordInvoice($additionVoucher, $request->validated());

        return $this->respond(new AdditionVoucherResource(
            $additionVoucher->fresh()->load(['lines.item', 'supplier', 'purchaseOrder']),
        ));
    }

    /**
     * Close without an invoice
     *
     * إقفال الإذن بدون فاتورة — the receipt will never be invoiced, and the
     * reason is written down. Only a posted, uninvoiced voucher can be closed:
     * a draft is still editable and deletable, so closing it would mean
     * nothing.
     *
     * @authenticated
     *
     * @urlParam addition_voucher integer required The voucher id. Example: 16
     * @bodyParam reason string required Why no invoice will arrive. Example: Free replacement for a damaged delivery
     *
     * @response 200 scenario="Closed" {"data":{"id":16,"type":"addition_voucher","invoicing_status":{"value":"closed_uninvoiced","label":"Closed Uninvoiced","color":"gray"},"closure_reason":"Free replacement for a damaged delivery"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not posted" {"error":{"code":"business_rule_violated","message":"Voucher AV-202609-0032 is not posted, so it cannot be closed."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function close(CloseAdditionVoucherRequest $request, AdditionVoucher $additionVoucher): JsonResponse
    {
        $this->authorize('invoice', $additionVoucher);

        $this->invoicing->closeWithoutInvoice(
            $additionVoucher,
            $request->string('reason')->toString(),
            $request->user(),
        );

        return $this->respond(new AdditionVoucherResource(
            $additionVoucher->fresh()->load(['lines.item', 'supplier', 'purchaseOrder']),
        ));
    }

    /**
     * Delete a draft addition voucher
     *
     * Draft only. A posted voucher has already moved stock and credited a
     * supplier; the correction for a mistake is a separate document, not a
     * deletion that leaves the ledger holding movements with no source.
     *
     * @authenticated
     *
     * @urlParam addition_voucher integer required The voucher id. Example: 16
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(AdditionVoucher $additionVoucher): JsonResponse
    {
        $this->authorize('delete', $additionVoucher);

        if ($additionVoucher->isPosted()) {
            throw new DomainException(__('errors.api.voucher_not_draft'));
        }

        $additionVoucher->delete();

        return $this->respondNoContent();
    }
}
