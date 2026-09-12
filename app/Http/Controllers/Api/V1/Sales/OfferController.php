<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Sales\ReplaceBoqRequest;
use App\Http\Requests\Api\V1\Sales\StoreOfferRequest;
use App\Http\Requests\Api\V1\Sales\UpdateOfferRequest;
use App\Http\Resources\Api\V1\Sales\OfferResource;
use App\Models\OfferItem;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Services\OfferTotalsService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group 13. Offers & BOQ
 *
 * The quotations submitted for an operation, and the bill of quantities behind
 * each one.
 *
 * **The money is derived, never posted.** A client sends line items —
 * description, unit, quantity, unit price — and the server multiplies, sums,
 * applies VAT and installation, and returns the totals. Accepting a
 * `grand_total` from the client would mean the printed offer, the pipeline
 * list and the ledger could each hold a different number for the same
 * quotation.
 */
class OfferController extends ApiController
{
    public function __construct(private readonly OfferTotalsService $totals) {}

    /**
     * List an operation's offers
     *
     * Ordered newest version first, so the current price is `data[0]`.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[is_winning] boolean Only the offer that won. Example: true
     * @queryParam sort string Allowed: version, submitted_at, grand_total. Example: -version
     *
     * @response 200 scenario="Success" {"data":[{"id":4,"type":"offer","project_id":1,"version":2,"grand_total":"1430000.00","is_winning":false}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewAny', ProjectOffer::class);
        $this->authorize('view', $project);

        $offers = ApiQuery::for(
            $project->offers()->getQuery()->with('submittedBy:id,name'),
            $request,
        )
            ->allowFilters(['is_winning' => ApiQuery::boolean('is_winning')])
            ->allowSorts(['version', 'submitted_at', 'grand_total'])
            ->defaultSort('-version')
            ->paginate();

        return $this->respondPaginated(OfferResource::collection($offers));
    }

    /**
     * Show an offer
     *
     * Carries the full BOQ: every group and every line.
     *
     * @authenticated
     *
     * @urlParam offer integer required The offer id. Example: 4
     *
     * @response 200 scenario="Success" {"data":{"id":4,"type":"offer","version":2,"subtotal":"1250000.00","tax_amount":"175000.00","grand_total":"1425000.00","groups":[{"id":7,"label":"Copper Offer","conductor_type":{"value":"copper","label":"Copper","color":null},"subtotal":"1250000.00","items":[{"id":21,"description":"Busbar 2500A","unit":"m","quantity":"120.0000","unit_price":"10416.67","line_total":"1250000.40"}]}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(ProjectOffer $offer): JsonResponse
    {
        $this->authorize('view', $offer);

        return $this->respond(new OfferResource(
            $offer->load(['groups.items', 'submittedBy:id,name']),
        ));
    }

    /**
     * Record an offer
     *
     * `version` is assigned by the server, per operation, and cannot be sent:
     * two people quoting the same job in the same minute would otherwise
     * collide on the unique index.
     *
     * The offer is created empty of lines and its totals are all zero. Send the
     * BOQ with `PUT /offers/{offer}/boq` to price it.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     * @bodyParam quotation_number string optional Your own reference. Example: Q-2026-0147
     * @bodyParam currency string optional Three-letter code; defaults to EGP. Example: EGP
     * @bodyParam vat_percentage number optional VAT rate applied to the subtotal. Example: 14
     * @bodyParam show_vat boolean optional Whether the printed offer shows a VAT line. Example: true
     * @bodyParam installation_percentage number optional Installation rate applied to the subtotal. Example: 5
     * @bodyParam show_installation boolean optional Whether the printed offer shows an installation line. Example: true
     * @bodyParam header_note string optional Printed above the tables. Example: Prices valid for 30 days
     * @bodyParam terms string optional Payment/delivery terms. Example: 50% advance
     * @bodyParam general_terms string optional Standard conditions. Example: Ex-works Cairo
     * @bodyParam notes string optional Internal note, not printed. Example: Consultant asked for aluminium alternative
     *
     * @response 201 scenario="Created" {"data":{"id":5,"type":"offer","project_id":1,"version":3,"grand_total":"0.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreOfferRequest $request, Project $project): JsonResponse
    {
        $this->authorize('create', ProjectOffer::class);
        $this->authorize('view', $project);

        $offer = $project->offers()->create($request->validated() + [
            // Both are defaulted by the model's `creating` hook as well; set
            // explicitly here so the row is right even if that hook is ever
            // narrowed.
            'submitted_by' => Auth::id(),
            'submitted_at' => now(),
        ]);

        // A freshly created offer has no lines, so its derived money columns
        // are still NULL from the insert. Running the totals service once here
        // settles them at zero, which keeps the promise that an offer's money
        // fields are always decimal strings — a client that has to handle
        // `null` on a brand-new offer and `"0.00"` a second later will get one
        // of the two branches wrong.
        $this->totals->recalculate($offer);

        return $this->respondCreated(new OfferResource($offer->fresh()->load('groups.items')));
    }

    /**
     * Update an offer's header
     *
     * Terms, notes, and the VAT / installation settings. Changing a rate
     * re-derives the totals from the existing lines immediately, so the client
     * gets the new grand total back in the same response.
     *
     * The lines themselves are replaced through the BOQ endpoint, not here.
     *
     * @authenticated
     *
     * @urlParam offer integer required The offer id. Example: 4
     * @bodyParam quotation_number string optional Example: Q-2026-0147
     * @bodyParam vat_percentage number optional Example: 14
     * @bodyParam show_vat boolean optional Example: true
     * @bodyParam installation_percentage number optional Example: 5
     * @bodyParam show_installation boolean optional Example: false
     * @bodyParam header_note string optional Example: Prices valid for 30 days
     * @bodyParam terms string optional Example: 50% advance
     * @bodyParam general_terms string optional Example: Ex-works Cairo
     * @bodyParam notes string optional Example: Revised after the site visit
     *
     * @response 200 scenario="Success" {"data":{"id":4,"type":"offer","vat_percentage":"14.00","tax_amount":"175000.00","grand_total":"1425000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateOfferRequest $request, ProjectOffer $offer): JsonResponse
    {
        $this->authorize('update', $offer);
        $this->assertNotLocked($offer);

        $offer->update($request->validated());

        // A changed VAT or installation rate changes the money. Recomputing
        // here means the response is never a stale total the client would then
        // display next to the new rate.
        $this->totals->recalculate($offer);

        return $this->respond(new OfferResource($offer->fresh()->load('groups.items')));
    }

    /**
     * Replace the BOQ
     *
     * Sends the whole bill of quantities in one request: a list of groups (the
     * priced tables — "Copper Offer", "Bi-Metal Offer") each holding its line
     * items. Whatever was there before is replaced.
     *
     * **Replace, not merge, and deliberately so.** A phone editing a BOQ over a
     * weak link cannot reliably sequence "add line 4, delete line 2, edit line
     * 3": a dropped request in the middle leaves the server holding a document
     * that never existed on either side. Sending the finished table is one
     * atomic decision the client can retry safely, and the `Idempotency-Key`
     * makes the retry free.
     *
     * Line totals, group subtotals, VAT, installation and the grand total are
     * all computed server-side and returned.
     *
     * @authenticated
     *
     * @urlParam offer integer required The offer id. Example: 4
     * @bodyParam groups object[] required The priced tables. Send an empty array to clear the BOQ.
     * @bodyParam groups[].label string required Table heading. Example: Copper Offer
     * @bodyParam groups[].conductor_type string optional One of the conductor_type enum values. Example: copper
     * @bodyParam groups[].items object[] required The lines of this table.
     * @bodyParam groups[].items[].description string required Example: Busbar trunking 2500A
     * @bodyParam groups[].items[].unit string optional Example: m
     * @bodyParam groups[].items[].quantity number required Example: 120
     * @bodyParam groups[].items[].unit_price number required Price per unit in EGP. Example: 10416.67
     *
     * @response 200 scenario="Success" {"data":{"id":4,"type":"offer","subtotal":"1250000.40","tax_amount":"175000.06","grand_total":"1425000.46","groups":[{"id":9,"label":"Copper Offer","subtotal":"1250000.40","items":[{"id":31,"description":"Busbar trunking 2500A","quantity":"120.0000","unit_price":"10416.67","line_total":"1250000.40"}]}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function replaceBoq(ReplaceBoqRequest $request, ProjectOffer $offer): JsonResponse
    {
        $this->authorize('update', $offer);
        $this->assertNotLocked($offer);

        DB::transaction(function () use ($request, $offer): void {
            // Items first, then groups. The foreign key is ON DELETE
            // CASCADE, but that only fires when the driver is enforcing
            // constraints; doing it explicitly means the rows go whether or
            // not it is, and the whole replace is inside one transaction so
            // there is no window where the offer holds half a BOQ.
            $offer->loadMissing('groups');
            OfferItem::whereIn('offer_group_id', $offer->groups->pluck('id'))->delete();
            $offer->groups()->delete();

            foreach ($request->array('groups') as $groupIndex => $group) {
                $created = $offer->groups()->create([
                    'label' => $group['label'],
                    'conductor_type' => $group['conductor_type'] ?? null,

                    // Order is the order sent. Making the client send explicit
                    // sort keys would just be a second thing to get wrong.
                    'sort_order' => $groupIndex,

                    // Overwritten by OfferTotalsService below; seeded so the
                    // NOT NULL column is satisfied by the insert itself.
                    'subtotal' => 0,
                ]);

                foreach ($group['items'] ?? [] as $itemIndex => $item) {
                    $created->items()->create([
                        'description' => $item['description'],
                        'unit' => $item['unit'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'sort_order' => $itemIndex,
                    ]);
                }
            }
        });

        // Lines → group subtotals → offer subtotal → VAT → installation →
        // grand total, and the headline `financial_amount` mirrors it.
        $this->totals->recalculate($offer);

        return $this->respond(new OfferResource($offer->fresh()->load('groups.items')));
    }

    /**
     * Delete an offer
     *
     * @authenticated
     *
     * @urlParam offer integer required The offer id. Example: 4
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Winning offer" {"error":{"code":"business_rule_violated","message":"This offer is marked as the winning offer of an active operation and can no longer be changed."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(ProjectOffer $offer): JsonResponse
    {
        $this->authorize('delete', $offer);
        $this->assertNotLocked($offer);

        $offer->delete();

        return $this->respondNoContent();
    }

    /**
     * The offer that won an operation is the price the operation was sold at.
     * Once the operation is Active, that figure is what the cost centre is
     * measured against and what the customer signed, so editing or deleting it
     * would rewrite history rather than correct a draft.
     */
    private function assertNotLocked(ProjectOffer $offer): void
    {
        if ($offer->is_winning) {
            throw new DomainException(__('errors.api.offer_locked'));
        }
    }
}
