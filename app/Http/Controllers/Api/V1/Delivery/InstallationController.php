<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Enums\AttachmentCategory;
use App\Enums\InstallationStatus;
use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Delivery\StoreInstallationRequest;
use App\Http\Requests\Api\V1\Delivery\UpdateInstallationRequest;
use App\Http\Resources\Api\V1\Delivery\InstallationResource;
use App\Models\Installation;
use App\Services\InstallationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * @group 27. Installations
 *
 * مرحلة التركيب — installing what was delivered, on the customer's site.
 *
 * Pending → **start** → In Progress → **complete** → Completed. Each
 * transition stamps its own timestamp, which is why there is no writable
 * `status`: a status a client can set is a status that drifts from the times
 * beside it.
 *
 * Installation **expenses** are not modelled here. They reach the operation
 * through the general ledger, tagged to it like every other cost, so that the
 * operation's cost file has one source rather than a parallel field only this
 * screen knows about.
 */
class InstallationController extends ApiController
{
    public function __construct(
        private readonly InstallationService $installations,
    ) {}

    /**
     * List installations
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[status] string pending, in_progress or completed. Example: in_progress
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[delivery_voucher] integer Delivery voucher id. Example: 18
     * @queryParam sort string Allowed: status, started_at, completed_at, created_at. Example: -created_at
     * @queryParam include string Allowed: project, deliveryVoucher. Example: project
     *
     * @response 200 scenario="Success" {"data":[{"id":3,"type":"installation","status":{"value":"in_progress","label":"In Progress","color":"info"},"project_id":4,"started_at":"2026-09-13T08:00:00+00:00"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Installation::class);

        $installations = ApiQuery::for(Installation::query(), $request)
            ->allowFilters([
                'status' => ApiQuery::exact('status'),
                'project' => ApiQuery::exact('project_id'),
                'delivery_voucher' => ApiQuery::exact('delivery_voucher_id'),
            ])
            ->allowSorts(['status', 'started_at', 'completed_at', 'created_at'])
            ->allowIncludes(['project', 'deliveryVoucher'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->respondPaginated(InstallationResource::collection($installations));
    }

    /**
     * Show an installation
     *
     * @authenticated
     *
     * @urlParam installation integer required The installation id. Example: 3
     *
     * @response 200 scenario="Success" {"data":{"id":3,"type":"installation","status":{"value":"completed","label":"Completed","color":"success"},"started_at":"2026-09-13T08:00:00+00:00","completed_at":"2026-09-14T17:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(Installation $installation): JsonResponse
    {
        $this->authorize('view', $installation);

        return $this->respond(new InstallationResource(
            $installation->load(['project', 'deliveryVoucher']),
        ));
    }

    /**
     * Open an installation
     *
     * Always starts Pending. The timestamps belong to the transitions.
     *
     * @authenticated
     *
     * @bodyParam project_id integer required The operation being installed. Example: 4
     * @bodyParam delivery_voucher_id integer optional The delivery it follows. Example: 18
     * @bodyParam notes string optional Example: Third floor, crane access needed
     *
     * @response 201 scenario="Created" {"data":{"id":3,"type":"installation","status":{"value":"pending","label":"Pending","color":"gray"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreInstallationRequest $request): JsonResponse
    {
        $this->authorize('create', Installation::class);

        $installation = Installation::create([
            'project_id' => $request->integer('project_id'),
            'delivery_voucher_id' => $request->input('delivery_voucher_id'),
            'status' => InstallationStatus::Pending,
            'notes' => $request->input('notes'),
            'created_by' => Auth::id(),
        ]);

        return $this->respondCreated(new InstallationResource(
            $installation->load(['project', 'deliveryVoucher']),
        ));
    }

    /**
     * Update an installation
     *
     * Notes and links only — see the class note on why there is no `status`.
     *
     * @authenticated
     *
     * @urlParam installation integer required The installation id. Example: 3
     *
     * @bodyParam notes string optional Example: Crane booked for Thursday
     * @bodyParam delivery_voucher_id integer optional Example: 18
     *
     * @response 200 scenario="Updated" {"data":{"id":3,"type":"installation","notes":"Crane booked for Thursday"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateInstallationRequest $request, Installation $installation): JsonResponse
    {
        $this->authorize('update', $installation);

        $installation->update($request->validated());

        return $this->respond(new InstallationResource(
            $installation->fresh()->load(['project', 'deliveryVoucher']),
        ));
    }

    /**
     * Start the installation
     *
     * Pending only, and it stamps `started_at`.
     *
     * @authenticated
     *
     * @urlParam installation integer required The installation id. Example: 3
     *
     * @response 200 scenario="Started" {"data":{"id":3,"type":"installation","status":{"value":"in_progress","label":"In Progress","color":"info"},"started_at":"2026-09-13T08:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Wrong stage" {"error":{"code":"business_rule_violated","message":"Cannot move from completed; expected pending."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function start(Installation $installation): JsonResponse
    {
        $this->authorize('update', $installation);

        $this->installations->start($installation);

        return $this->respond(new InstallationResource(
            $installation->fresh()->load(['project', 'deliveryVoucher']),
        ));
    }

    /**
     * Complete the installation
     *
     * In Progress only, and it stamps `completed_at`. An installation cannot
     * jump straight from Pending to Completed: the start time is what the
     * duration on site is measured from, and an installation with no start has
     * no duration.
     *
     * An installation is finished BY the works-handover minutes (محاضر تسليم
     * الأعمال — Presentation.pptx slide 8). Send the signed minutes as
     * `handover_minutes` (multipart) and they are filed on the operation under
     * the `handover_minutes` attachment category; without a file, the operation
     * must already have minutes on file or the call fails with 422. Uploading
     * the minutes through `POST /attachments` completes the running
     * installation by itself.
     *
     * @authenticated
     *
     * @urlParam installation integer required The installation id. Example: 3
     *
     * @bodyParam handover_minutes file optional The signed works-handover minutes (max 40 MB). Required unless the operation already has minutes on file.
     *
     * @response 422 scenario="No handover minutes" {"message":"An installation can only be finished with the works handover minutes. Upload the minutes first.","errors":{"handover_minutes":["An installation can only be finished with the works handover minutes. Upload the minutes first."]}}
     * @response 200 scenario="Completed" {"data":{"id":3,"type":"installation","status":{"value":"completed","label":"Completed","color":"success"},"completed_at":"2026-09-14T17:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function complete(Request $request, Installation $installation): JsonResponse
    {
        $this->authorize('update', $installation);

        $request->validate([
            'handover_minutes' => ['nullable', 'file', 'max:40960'],
        ]);

        $file = $request->file('handover_minutes');

        // A Pending installation is refused by the service as a business rule
        // first; the minutes only matter for one that is actually running.
        if ($file === null && $installation->isInProgress()
            && ! $this->installations->hasHandoverMinutes($installation->project_id)) {
            $this->failValidation('handover_minutes', __('errors.installations.handover_minutes_required'));
        }

        if ($file !== null && $installation->isInProgress()) {
            // Same storage rule as POST /attachments: the client's filename is
            // display text, never a path segment.
            $path = $file->storeAs(
                'attachments/'.$installation->project_id.'/'.AttachmentCategory::HandoverMinutes->value,
                Str::uuid()->toString().'.'.strtolower((string) $file->getClientOriginalExtension()),
                'public',
            );

            // Filing the minutes completes the running installation (observer).
            $this->installations->attachHandoverMinutes($installation->project, $path, $file->getClientOriginalName());
            $installation->refresh();
        }

        if (! $installation->isCompleted()) {
            $this->installations->complete($installation);
        }

        return $this->respond(new InstallationResource(
            $installation->fresh()->load(['project', 'deliveryVoucher']),
        ));
    }

    /**
     * Delete an installation
     *
     * @authenticated
     *
     * @urlParam installation integer required The installation id. Example: 3
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(Installation $installation): JsonResponse
    {
        $this->authorize('delete', $installation);

        $installation->delete();

        return $this->respondNoContent();
    }
}
