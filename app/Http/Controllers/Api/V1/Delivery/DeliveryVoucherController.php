<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Enums\DeliveryVoucherStatus;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Delivery\ReplaceDeliveryVoucherLinesRequest;
use App\Http\Requests\Api\V1\Delivery\StoreDeliveryVoucherRequest;
use App\Http\Requests\Api\V1\Delivery\UpdateDeliveryVoucherRequest;
use App\Http\Resources\Api\V1\Delivery\DeliveryVoucherResource;
use App\Models\DeliveryVoucher;
use App\Models\Item;
use App\Services\DeliveryVoucherService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group 25. Delivery vouchers
 *
 * إذن تسليم — finished goods leaving the store for a customer.
 *
 * The document turns on a **dual signature**: technical management and
 * financial management sign independently, in either order, through two
 * separate endpoints carrying two separate permissions. Neither is a field.
 *
 * The second signature to arrive **activates** the voucher, and activation is
 * the moment everything happens at once, in one transaction: the finished
 * goods are deducted from stock, the customer's account is debited with the
 * delivered value, and — when no work order is still open on the operation —
 * its cost centre is closed into cost of goods sold.
 *
 * That is why the signature and the activation are atomic. If activation fails
 * (insufficient finished goods, say), the signature must not survive it;
 * otherwise the voucher would show an approval that never took effect while
 * the user was told it failed.
 *
 * Cost-centre closing is deliberately silent and deliberately last: a missing
 * account or an early delivery must never roll back a delivery that physically
 * happened. Finance can always close the centre by hand afterwards.
 */
class DeliveryVoucherController extends ApiController
{
    public function __construct(
        private readonly DeliveryVoucherService $vouchers,
    ) {}

    /**
     * List delivery vouchers
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the voucher number or supply order number. Example: DV-2026
     * @queryParam filter[status] string draft, pending_approval, active or cancelled. Example: active
     * @queryParam filter[customer] integer Customer id. Example: 7
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[invoicing_status] string not_invoiced, partially_invoiced or fully_invoiced. Example: not_invoiced
     * @queryParam filter[voucher_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: voucher_number, voucher_date, total_value, created_at. Example: -voucher_date
     * @queryParam include string Allowed: customer, project. Example: customer
     *
     * @response 200 scenario="Success" {"data":[{"id":18,"type":"delivery_voucher","voucher_number":"DV-202609-0005","status":{"value":"active","label":"Active","color":"success"},"total_value":"250000.00","invoicing":{"status":{"value":"not_invoiced","label":"Not Invoiced","color":"warning"},"invoiced_value":"0.00"},"approvals":{"fully_approved":true}}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DeliveryVoucher::class);

        $vouchers = ApiQuery::for(
            DeliveryVoucher::query()
                ->withCount(['lines', 'invoices'])
                // `lines_value` is quantity x unit cost summed over the lines.
                // Reading the model's accessor here would lazy-load that
                // relation once per row; one subquery instead, which the
                // resource prefers when present (Finding #15).
                ->withSum('lines as lines_value_sum', DB::raw('quantity * unit_cost')),
            $request,
        )
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'customer' => ApiQuery::exact('customer_id'),
                'project' => ApiQuery::exact('project_id'),
                'invoicing_status' => ApiQuery::exact('invoicing_status'),
                'voucher_date' => ApiQuery::dateBetween('voucher_date'),
            ])
            ->allowSearch(['voucher_number', 'supply_order_number'])
            ->allowSorts(['voucher_number', 'voucher_date', 'total_value', 'created_at'])
            ->allowIncludes(['customer', 'project'])
            ->defaultSort('-voucher_date')
            ->paginate();

        return $this->respondPaginated(DeliveryVoucherResource::collection($vouchers));
    }

    /**
     * Show a delivery voucher
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The voucher id. Example: 18
     *
     * @response 200 scenario="Success" {"data":{"id":18,"type":"delivery_voucher","voucher_number":"DV-202609-0005","approvals":{"technical_approved":true,"financial_approved":false,"fully_approved":false},"lines":[{"item_id":21,"quantity":"9.0000","unit_cost":"27777.78","line_value":"250000.02"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('view', $deliveryVoucher);

        return $this->respond(new DeliveryVoucherResource($this->loaded($deliveryVoucher)));
    }

    /**
     * Raise a delivery voucher
     *
     * Starts as a **Draft** with a server-generated number. Nothing moves and
     * no signature can be sent with it — both are separate acts with separate
     * permissions.
     *
     * `unit_cost` falls back to the item card when omitted, which is the usual
     * case: what a delivery is worth is what the goods cost, not a price typed
     * at the loading bay.
     *
     * @authenticated
     *
     * @bodyParam customer_id integer required Who is receiving the goods. Example: 7
     * @bodyParam project_id integer optional The operation being delivered. Example: 4
     * @bodyParam supply_order_number string optional The customer's own order reference. Example: SO-2026-88
     * @bodyParam voucher_date date optional Defaults to today. Example: 2026-09-12
     * @bodyParam plates_count integer optional Example: 9
     * @bodyParam protection_degree string optional Example: IP54
     * @bodyParam insulation_voltage string optional Example: 1000V
     * @bodyParam notes string optional Example: Delivered to the site gate
     * @bodyParam lines object[] optional What is being handed over.
     * @bodyParam lines[].item_id integer required Example: 21
     * @bodyParam lines[].quantity number required Example: 9
     * @bodyParam lines[].unit_cost number optional Defaults to the item's current cost. Example: 27777.78
     *
     * @response 201 scenario="Created" {"data":{"id":18,"type":"delivery_voucher","voucher_number":"DV-202609-0005","status":{"value":"draft","label":"Draft","color":"gray"},"total_value":"0.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreDeliveryVoucherRequest $request): JsonResponse
    {
        $this->authorize('create', DeliveryVoucher::class);

        $voucher = DB::transaction(function () use ($request): DeliveryVoucher {
            $voucher = DeliveryVoucher::create([
                'voucher_number' => DeliveryVoucher::generateVoucherNumber(),
                'customer_id' => $request->integer('customer_id'),
                'project_id' => $request->input('project_id'),
                'supply_order_number' => $request->input('supply_order_number'),
                'voucher_date' => $request->input('voucher_date', now()->toDateString()),
                'plates_count' => $request->input('plates_count'),
                'protection_degree' => $request->input('protection_degree'),
                'insulation_voltage' => $request->input('insulation_voltage'),
                'notes' => $request->input('notes'),
                'status' => DeliveryVoucherStatus::Draft,
                'created_by' => Auth::id(),
            ]);

            $this->writeLines($voucher, $request->array('lines'));

            return $voucher;
        });

        return $this->respondCreated(new DeliveryVoucherResource($this->loaded($voucher->fresh())));
    }

    /**
     * Update a delivery voucher
     *
     * Header fields only, and only while the voucher is not yet active — the
     * policy enforces that, so an active voucher answers **403**.
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The voucher id. Example: 18
     *
     * @bodyParam supply_order_number string optional Example: SO-2026-91
     * @bodyParam notes string optional Example: Second attempt, gate was closed
     *
     * @response 200 scenario="Updated" {"data":{"id":18,"type":"delivery_voucher","supply_order_number":"SO-2026-91"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateDeliveryVoucherRequest $request, DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('update', $deliveryVoucher);

        $deliveryVoucher->update($request->validated());

        return $this->respond(new DeliveryVoucherResource($this->loaded($deliveryVoucher->fresh())));
    }

    /**
     * Replace the goods on a voucher
     *
     * The whole list in one atomic request. Only while the voucher is
     * inactive: once it activates, the stock has moved and the customer has
     * been debited for exactly these lines.
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The voucher id. Example: 18
     *
     * @bodyParam lines object[] required The complete list. Send `[]` to clear it.
     * @bodyParam lines[].item_id integer required Example: 21
     * @bodyParam lines[].quantity number required Must be greater than zero. Example: 9
     * @bodyParam lines[].unit_cost number optional Defaults to the item's current cost. Example: 27777.78
     *
     * @response 200 scenario="Replaced" {"data":{"id":18,"type":"delivery_voucher","lines_value":"250000.02","lines":[{"item_id":21,"quantity":"9.0000"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceLines(ReplaceDeliveryVoucherLinesRequest $request, DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('update', $deliveryVoucher);

        DB::transaction(fn () => $this->writeLines($deliveryVoucher, $request->array('lines'), replace: true));

        return $this->respond(new DeliveryVoucherResource($this->loaded($deliveryVoucher->fresh())));
    }

    /**
     * Technical management signature
     *
     * اعتماد الإدارة الفنية — one half of the dual approval. If the financial
     * signature is already present this call also **activates** the voucher,
     * with everything that entails.
     *
     * The signature and the activation are one unit of work. A failure during
     * activation — insufficient finished-goods stock is the common one — rolls
     * the signature back too, so the voucher never shows an approval that did
     * not take effect.
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The voucher id. Example: 18
     *
     * @response 200 scenario="Signed" {"data":{"id":18,"type":"delivery_voucher","status":{"value":"pending_approval","label":"Pending Approval","color":"warning"},"approvals":{"technical_approved":true,"financial_approved":false}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Insufficient stock on activation" {"error":{"code":"business_rule_violated","message":"Insufficient stock for 'Main panel' in Finished Goods. Available: 4, Requested: 9."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function approveTechnical(DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('approveTechnical', $deliveryVoucher);

        $this->vouchers->approveTechnical($deliveryVoucher, $this->currentUser());

        return $this->respond(new DeliveryVoucherResource($this->loaded($deliveryVoucher->fresh())));
    }

    /**
     * Financial management signature
     *
     * اعتماد الإدارة المالية — the other half. Same atomicity, same activation
     * behaviour. Held by a different permission from the technical signature
     * on purpose: one person holding both would defeat the point of having
     * two.
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The voucher id. Example: 18
     *
     * @response 200 scenario="Activated" {"data":{"id":18,"type":"delivery_voucher","status":{"value":"active","label":"Active","color":"success"},"total_value":"250000.02","activated_at":"2026-09-12T15:00:00+00:00","approvals":{"fully_approved":true}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="No lines" {"error":{"code":"business_rule_violated","message":"Delivery voucher DV-202609-0005 has no lines."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function approveFinancial(DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('approveFinancial', $deliveryVoucher);

        $this->vouchers->approveFinancial($deliveryVoucher, $this->currentUser());

        return $this->respond(new DeliveryVoucherResource($this->loaded($deliveryVoucher->fresh())));
    }

    /**
     * Cancel a delivery voucher
     *
     * Only before activation. An active voucher's goods have already left and
     * its customer has already been debited; the correction for that is a
     * credit document, not a cancelled delivery that leaves the ledger holding
     * movements with no source.
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The voucher id. Example: 18
     *
     * @response 200 scenario="Cancelled" {"data":{"id":18,"type":"delivery_voucher","status":{"value":"cancelled","label":"Cancelled","color":"danger"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function cancel(DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('cancel', $deliveryVoucher);

        $this->vouchers->cancel($deliveryVoucher);

        return $this->respond(new DeliveryVoucherResource($this->loaded($deliveryVoucher->fresh())));
    }

    /**
     * Delete a delivery voucher
     *
     * Inactive vouchers only, for the same reason as cancel.
     *
     * @authenticated
     *
     * @urlParam delivery_voucher integer required The voucher id. Example: 18
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(DeliveryVoucher $deliveryVoucher): JsonResponse
    {
        $this->authorize('delete', $deliveryVoucher);

        if ($deliveryVoucher->isActive()) {
            throw new DomainException(__('errors.api.delivery_voucher_not_editable'));
        }

        $deliveryVoucher->delete();

        return $this->respondNoContent();
    }

    /**
     * The services take a `User` rather than reading Auth themselves, because
     * the panel calls them from queued contexts too. Route middleware
     * guarantees one is present here.
     */
    private function currentUser(): \App\Models\User
    {
        /** @var \App\Models\User $user */
        $user = request()->user();

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeLines(DeliveryVoucher $voucher, array $lines, bool $replace = false): void
    {
        if ($lines === [] && ! $replace) {
            return;
        }

        $itemCosts = Item::query()
            ->whereIn('id', array_column($lines, 'item_id'))
            ->pluck('unit_cost', 'id');

        $voucher->lines()->delete();

        foreach ($lines as $line) {
            $voucher->lines()->create([
                'item_id' => $line['item_id'],
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'] ?? $itemCosts[$line['item_id']] ?? 0,
            ]);
        }
    }

    private function loaded(DeliveryVoucher $voucher): DeliveryVoucher
    {
        return $voucher->load(['lines.item', 'customer', 'project'])
            ->loadCount(['lines', 'invoices']);
    }
}
