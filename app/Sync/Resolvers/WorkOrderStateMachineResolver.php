<?php

declare(strict_types=1);

namespace App\Sync\Resolvers;

use App\Enums\WorkOrderStatus;
use App\Models\DeviceToken;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;

/**
 * Work Order resolver. Extends LWW with a strict state machine.
 *
 * Forward-only transitions:
 *
 *   Draft → Pending (approve_order)              [PMO-manager gate, سلايد 5]
 *   Pending → InProgress (start)
 *   InProgress → QaReview (submit_qa)
 *   QaReview → QaReview (approve_qa)             [stays in QaReview but sets approver]
 *   QaReview → Completed (complete)              [requires qa_approved_by set]
 *   * → Cancelled                                [admin only — not via sync push]
 *
 * Idempotency twist: "already advanced" reads as success on retry.
 * The client may have submitted a `start` operation, lost the response,
 * and another operator may have approved the WO during the gap. When
 * the original submitter's queue drains the next time it sees the
 * server, it would otherwise get a conflict for trying to take a WO
 * already in QaReview from Pending. Instead we detect this case and
 * return a noop result, so the offline outbox clears without alarming
 * the operator.
 *
 * This is the same idempotent-advance pattern the Filament action layer
 * already uses (see WorkOrderResource); we replicate it here so the
 * offline path inherits the same forgiving behaviour.
 */
final class WorkOrderStateMachineResolver extends LastWriterWinsResolver
{
    public function __construct()
    {
        parent::__construct(WorkOrder::class, 'work_order');
    }

    public function resolve(array $operation, DeviceToken $token): ResolverResult
    {
        // Specialised "transition" action carrying just a target state
        // and (optionally) actuals. Falls back to plain LWW for "upsert".
        $action = $operation['action'] ?? 'upsert';

        if ($action === 'transition') {
            return $this->resolveTransition($operation, $token);
        }

        return parent::resolve($operation, $token);
    }

    private function resolveTransition(array $operation, DeviceToken $token): ResolverResult
    {
        $uuid       = $operation['record_uuid'] ?? null;
        $fields     = $operation['fields'] ?? [];
        $targetRaw  = $fields['status'] ?? null;

        if ($uuid === null || $targetRaw === null) {
            return ResolverResult::rejected('validation_failed', 'record_uuid and fields.status are required.');
        }

        $target = WorkOrderStatus::tryFrom((string) $targetRaw);
        if ($target === null) {
            return ResolverResult::rejected('validation_failed', "Unknown target status: {$targetRaw}");
        }

        /** @var WorkOrder|null $wo */
        $wo = WorkOrder::query()->where('uuid', $uuid)->first();
        if ($wo === null) {
            return ResolverResult::rejected('fk_missing', "WorkOrder {$uuid} not found.");
        }

        // Authenticate so service-layer queries see Auth::id().
        \Illuminate\Support\Facades\Auth::setUser($token->user);

        /** @var WorkOrderService $service */
        $service = app(WorkOrderService::class);

        try {
            // Decide which service call corresponds to (current → target),
            // and treat "already advanced past target" as noop.
            $currentRank = $this->rankOf($wo->status);
            $targetRank  = $this->rankOf($target);

            if ($targetRank <= $currentRank && $target !== WorkOrderStatus::Cancelled) {
                // The client is asking for a transition the server has
                // already moved past. Idempotent success.
                return ResolverResult::noop($wo);
            }

            // Only allow single-step forward transitions via sync. A
            // multi-step jump (e.g. Pending → Completed) requires the
            // intermediate states' side effects to fire.
            if ($targetRank - $currentRank > 1) {
                return ResolverResult::conflicted($wo, 'illegal_transition');
            }

            match ($target) {
                WorkOrderStatus::Pending    => $service->approveOrder($wo),
                WorkOrderStatus::InProgress => $service->start($wo),
                WorkOrderStatus::QaReview   => $service->submitForQa(
                    $wo,
                    (float) ($fields['produced_quantity'] ?? 0),
                    (float) ($fields['waste_quantity'] ?? 0),
                ),
                WorkOrderStatus::Completed  => $this->approveAndComplete($wo, $service, $fields),
                WorkOrderStatus::Cancelled  => ResolverResult::rejected('illegal_transition', 'Cancellation must go through admin.'),
                default                     => null,
            };

            $wo->refresh();
            $wo->sync_origin       = $token->device_id;
            $wo->client_updated_at = $operation['client_updated_at'] ?? null;
            $wo->saveQuietly();

            return ResolverResult::applied($wo);
        } catch (\RuntimeException $e) {
            // Service-layer business rules (e.g. "not in QaReview")
            return ResolverResult::conflicted($wo, 'illegal_transition');
        } catch (\Throwable $e) {
            return ResolverResult::rejected('validation_failed', $e->getMessage());
        }
    }

    /**
     * QA approval and completion are normally separate steps in the
     * Filament UI, but on the factory floor an operator finishing a WO
     * may also be the one approving QA on a single-operator shift. The
     * client submits a single `complete` transition; we approve (if
     * not yet approved) and then complete, atomically.
     */
    private function approveAndComplete(WorkOrder $wo, WorkOrderService $service, array $fields): void
    {
        if (! $wo->isQaApproved()) {
            $service->approveQa($wo, $fields['qa_notes'] ?? null);
            $wo->refresh();
        }

        $service->complete($wo);
    }

    private function rankOf(WorkOrderStatus $status): int
    {
        return match ($status) {
            WorkOrderStatus::Draft      => 0,
            WorkOrderStatus::Pending    => 1,
            WorkOrderStatus::InProgress => 2,
            WorkOrderStatus::QaReview   => 3,
            WorkOrderStatus::Completed  => 4,
            WorkOrderStatus::Cancelled  => 99, // Sideways branch
        };
    }
}
