<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Enums\ReservationStatus;
use App\Enums\WarehouseType;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Procurement\StoreStockReservationRequest;
use App\Http\Resources\Api\V1\Procurement\StockReservationResource;
use App\Models\Item;
use App\Models\Project;
use App\Models\StockReservation;
use App\Services\ReservationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 16. Stock reservations
 *
 * Holding stock for an operation (حجز الكمية للعملية).
 *
 * A reservation lowers an item's **available** quantity without moving
 * anything physically, so the same material cannot be promised to two jobs.
 * Releasing it hands the quantity back — normally when the materials are
 * actually issued to manufacturing.
 *
 * Reserving more than is available is refused by InventoryService, and arrives
 * as `422 business_rule_violated` naming the item, the warehouse, and the
 * quantity that was actually free.
 */
class StockReservationController extends ApiController
{
    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * List reservations
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[project] integer Operation id. Example: 1
     * @queryParam filter[item] integer Item id. Example: 7
     * @queryParam filter[status] string active or released. Example: active
     * @queryParam filter[warehouse] string A warehouse_type value. Example: raw_materials
     * @queryParam sort string Allowed: created_at, quantity, released_at. Example: -created_at
     * @queryParam include string Allowed: project, item, createdBy. Example: item,project
     *
     * @response 200 scenario="Success" {"data":[{"id":22,"type":"stock_reservation","project_id":1,"item_id":7,"quantity":"120.0000","status":{"value":"active","label":"Active","color":"warning"},"warehouse":{"value":"raw_materials","label":"Raw Materials","color":null}}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockReservation::class);

        $reservations = ApiQuery::for(StockReservation::query(), $request)
            ->allowFilters([
                'project' => ApiQuery::exact('project_id'),
                'item' => ApiQuery::exact('item_id'),
                'status' => ApiQuery::exact('status'),
                'warehouse' => ApiQuery::exact('warehouse_type'),
            ])
            ->allowSorts(['created_at', 'quantity', 'released_at'])
            ->allowIncludes(['project', 'item', 'createdBy'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->respondPaginated(StockReservationResource::collection($reservations));
    }

    /**
     * Show a reservation
     *
     * @authenticated
     *
     * @urlParam stock_reservation integer required The reservation id. Example: 22
     *
     * @response 200 scenario="Success" {"data":{"id":22,"type":"stock_reservation","quantity":"120.0000","status":{"value":"active","label":"Active","color":"warning"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(StockReservation $stockReservation): JsonResponse
    {
        $this->authorize('view', $stockReservation);

        return $this->respond(new StockReservationResource(
            $stockReservation->load(['project', 'item', 'createdBy']),
        ));
    }

    /**
     * Reserve stock for an operation
     *
     * `warehouse` defaults to the item's home warehouse, which is derived from
     * its type — send it only to hold stock somewhere else.
     *
     * @authenticated
     *
     * @bodyParam project_id integer required The operation to hold the stock for. Example: 1
     * @bodyParam item_id integer required The item to hold. Example: 7
     * @bodyParam quantity number required How much to hold. Example: 120
     * @bodyParam warehouse string optional A warehouse_type value; defaults to the item's home warehouse. Example: raw_materials
     * @bodyParam notes string optional Example: Held for the first production batch
     *
     * @response 201 scenario="Created" {"data":{"id":22,"type":"stock_reservation","project_id":1,"item_id":7,"quantity":"120.0000","status":{"value":"active","label":"Active","color":"warning"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not enough free stock" {"error":{"code":"business_rule_violated","message":"Insufficient available stock to hold 'Copper Busbar' in Raw Materials. Available: 40, Requested: 120."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreStockReservationRequest $request): JsonResponse
    {
        $this->authorize('create', StockReservation::class);

        $reservation = $this->reservations->reserveForProject(
            project: Project::findOrFail($request->integer('project_id')),
            item: Item::findOrFail($request->integer('item_id')),
            quantity: (float) $request->input('quantity'),
            warehouse: $request->filled('warehouse')
                ? WarehouseType::from($request->string('warehouse')->toString())
                : null,
            notes: $request->input('notes'),
        );

        return $this->respondCreated(new StockReservationResource(
            $reservation->load(['project', 'item']),
        ));
    }

    /**
     * Reserve an operation's whole approved BOM
     *
     * Holds each line of the operation's latest **approved** BOM at its
     * waste-adjusted quantity, from each item's home warehouse. This is the
     * bulk version of the endpoint above and the one a planning screen calls.
     *
     * Either every line is held or none is: the whole run is one transaction,
     * so an operation is never left half-reserved with no record of which half.
     * If the operation has no approved BOM the result is an empty list, not an
     * error — it simply has nothing to hold yet.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 201 scenario="Reserved" {"data":[{"id":22,"type":"stock_reservation","item_id":7,"quantity":"126.0000","status":{"value":"active","label":"Active","color":"warning"}}],"meta":{"request_id":"9f1c","api_version":"1","count":1}}
     * @response 422 scenario="Not enough free stock for one of the lines" {"error":{"code":"business_rule_violated","message":"Insufficient available stock to hold 'Copper Busbar' in Raw Materials. Available: 40, Requested: 126."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function reserveApprovedBom(Request $request, Project $project): JsonResponse
    {
        $this->authorize('create', StockReservation::class);
        $this->authorize('view', $project);

        // One transaction around the whole run. reserveForProject() wraps each
        // line individually, so without this a shortage on line six would
        // leave lines one to five held with nothing recording that the batch
        // failed — and the next attempt would double-hold them.
        $reservations = \Illuminate\Support\Facades\DB::transaction(
            fn () => $this->reservations->reserveApprovedBomFor($project),
        );

        // ReservationService returns a plain support Collection, which has no
        // load(); wrapping it in an Eloquent collection eager-loads the items
        // in one query instead of one per reservation.
        $loaded = (new EloquentCollection($reservations->all()))->load('item');

        // 201: this endpoint creates rows, even when the list it returns is
        // empty because the operation had no approved BOM to hold.
        return $this->respondCollection(
            StockReservationResource::collection($loaded)->resolve($request),
            201,
        );
    }

    /**
     * Release a reservation
     *
     * Returns the held quantity to available stock. Releasing an
     * already-released reservation is refused rather than silently accepted:
     * the service is idempotent, so a success would tell the caller a release
     * happened when nothing did.
     *
     * @authenticated
     *
     * @urlParam stock_reservation integer required The reservation id. Example: 22
     *
     * @response 200 scenario="Released" {"data":{"id":22,"type":"stock_reservation","status":{"value":"released","label":"Released","color":"gray"},"released_at":"2026-09-08T12:30:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Already released" {"error":{"code":"business_rule_violated","message":"This reservation has already been released."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function release(StockReservation $stockReservation): JsonResponse
    {
        $this->authorize('update', $stockReservation);

        if ($stockReservation->status !== ReservationStatus::Active) {
            throw new DomainException(__('errors.api.reservation_already_released'));
        }

        $this->reservations->release($stockReservation);

        return $this->respond(new StockReservationResource(
            $stockReservation->fresh()->load(['project', 'item']),
        ));
    }
}
