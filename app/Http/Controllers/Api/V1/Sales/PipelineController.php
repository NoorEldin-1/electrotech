<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\LostReason;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Sales\CancelToLostRequest;
use App\Http\Requests\Api\V1\Sales\SetAlarmRequest;
use App\Http\Resources\Api\V1\Sales\ProjectResource;
use App\Models\Project;
use App\Services\SalesPipelineService;
use Illuminate\Http\JsonResponse;

/**
 * @group 12. Sales pipeline
 *
 * Moving an operation between stages:
 *
 *     Draft → Tender → In Hand → In Progress (active operation)
 *                           ↓
 *                         Lost
 *
 * These are separate endpoints rather than a `status` field on PATCH for two
 * reasons. Each move has its own permission (`projects.move_to_active` is not
 * `projects.edit`), and each has pre-conditions the caller cannot see —
 * moving to Active needs both the consultant's acceptance email and the
 * manager's approval on file.
 *
 * Every rule here lives in App\Services\SalesPipelineService, which the
 * Filament panel calls too. A pre-condition failure arrives as
 * **422 `business_rule_violated`** with a message written for a person: show
 * it to the user directly rather than mapping it.
 */
class PipelineController extends ApiController
{
    public function __construct(private readonly SalesPipelineService $pipeline) {}

    /**
     * Move to Tender
     *
     * Requires at least one recorded offer, so the Tender list always carries
     * a meaningful current price.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","status":{"value":"tender","label":"Tender","color":"info"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="No offer yet" {"error":{"code":"business_rule_violated","message":"Cannot move to Tender: at least one offer must be recorded first."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function moveToTender(Project $project): JsonResponse
    {
        $this->authorize('moveToTender', $project);

        $this->pipeline->moveToTender($project);

        return $this->respondProject($project);
    }

    /**
     * Move to In Hand
     *
     * The client has accepted in principle and asked for the SMB (the
     * operation's Submittal document). The resulting `smb_status` is derived
     * from whether that document is already attached — upload it through the
     * attachments endpoint with `category=submittal` before or after this call.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","status":{"value":"in_hand","label":"In Hand","color":"warning"},"smb_status":"pending","has_smb":false},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function moveToInHand(Project $project): JsonResponse
    {
        $this->authorize('moveToInHand', $project);

        $this->pipeline->moveToInHand($project);

        return $this->respondProject($project);
    }

    /**
     * Move to Active
     *
     * The last gate before manufacturing can start. Both signals must already
     * be on the record: `acceptance_email_at` (set through PATCH /projects)
     * and a manager approval (POST /manager-approve). The latest offer is
     * marked as the winning one, the end date is filled in if Sales left it
     * blank, and every department is notified.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","status":{"value":"in_progress","label":"In Progress","color":"primary"}},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Manager approval missing" {"error":{"code":"business_rule_violated","message":"Cannot move to Active: manager approval is required."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function moveToActive(Project $project): JsonResponse
    {
        $this->authorize('moveToActive', $project);

        $this->pipeline->moveToActive($project);

        return $this->respondProject($project);
    }

    /**
     * Approve as manager
     *
     * Records the signature that `move-to-active` checks for. Deliberately a
     * separate step with its own permission: a Sales user fills in the
     * acceptance date, a Sales manager signs off, then either may complete the
     * move.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","manager_approved_at":"2026-09-08T11:04:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function managerApprove(Project $project): JsonResponse
    {
        $this->authorize('managerApprove', $project);

        $this->pipeline->managerApprove($project);

        return $this->respondProject($project);
    }

    /**
     * Cancel to Lost
     *
     * Terminal — there is no transition out of Lost. The reason and, where
     * known, the competitor who won are captured for the year-end loss
     * analysis, which is the whole point of recording it rather than deleting
     * the operation.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     * @bodyParam lost_reason string required One of the lost_reason enum values. Example: high_price
     * @bodyParam lost_reason_note string optional Free-text detail. Example: 12% above the winning bid
     * @bodyParam winning_competitor string optional Who won it. Example: Schneider
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","status":{"value":"lost","label":"Lost","color":"danger"},"winning_competitor":"Schneider"},"meta":{"request_id":"9f1c","api_version":"1"}}
     * @response 422 scenario="Not in a cancellable stage" {"error":{"code":"business_rule_violated","message":"Illegal transition: project is 'in_progress', expected one of [tender, in_hand]."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function cancelToLost(CancelToLostRequest $request, Project $project): JsonResponse
    {
        $this->authorize('cancelToLost', $project);

        $this->pipeline->cancelToLost(
            project: $project,
            reason: LostReason::from($request->string('lost_reason')->toString()),
            note: $request->input('lost_reason_note'),
            winningCompetitor: $request->input('winning_competitor'),
        );

        return $this->respondProject($project);
    }

    /**
     * Set a reminder
     *
     * The bell Sales sets on an operation it is chasing. Sending this again
     * replaces the existing reminder.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     * @bodyParam alarm_at string required ISO-8601 timestamp, in the future. Example: 2026-09-20T09:00:00Z
     * @bodyParam alarm_note string optional What to chase. Example: Call the consultant about the revised BOQ
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","alarm_at":"2026-09-20T09:00:00+00:00","alarm_note":"Call the consultant"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function setAlarm(SetAlarmRequest $request, Project $project): JsonResponse
    {
        $this->authorize('setAlarm', $project);

        $this->pipeline->setAlarm(
            project: $project,
            when: \Illuminate\Support\Carbon::parse($request->string('alarm_at')->toString()),
            note: $request->input('alarm_note'),
        );

        return $this->respondProject($project);
    }

    /**
     * Clear the reminder
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"type":"project","alarm_at":null,"alarm_note":null},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function clearAlarm(Project $project): JsonResponse
    {
        $this->authorize('setAlarm', $project);

        $this->pipeline->clearAlarm($project);

        return $this->respondProject($project);
    }

    /**
     * Every transition answers with the operation in its new state, so the
     * client can render the result without a follow-up GET on a connection
     * that may not survive one.
     */
    private function respondProject(Project $project): JsonResponse
    {
        return $this->respond(new ProjectResource(
            $project->fresh()->load(['customer', 'latestOffer']),
        ));
    }
}
