<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\DeviceToken;
use App\Models\SyncConflict;
use App\Models\SyncOperationLog;
use App\Sync\Resolvers\AppendOnlyResolver;
use App\Sync\Resolvers\ReadOnlyResolver;
use App\Sync\Resolvers\Resolver;
use App\Sync\Resolvers\ResolverResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The push pipeline.
 *
 * One OperationProcessor handles a batch of client operations within
 * a single sync push request. Each operation is processed inside its
 * own DB transaction, so a mid-batch failure does NOT roll back the
 * preceding successes — the client retries only the unfinished tail.
 *
 * Dedupe: each operation carries a client-minted op_uuid. Before the
 * resolver runs, we look up (device_token_id, op_uuid) in
 * sync_operation_log. A hit means we've seen this op before — replay
 * the cached response without re-executing.
 *
 * Conflict logging: when the resolver returns `conflicted`, the
 * processor writes a SyncConflict row with the rejected payload and
 * the server's authoritative state. The push response includes the
 * conflict id so the client can surface a notification immediately
 * rather than waiting for the next pull.
 *
 * Concurrency: per-record uuid serialisation is provided by the
 * record_version optimistic-locking semantics in the LWW resolver, not
 * by an explicit lock here. Two concurrent batches that race on the
 * same record will see one apply (version 1 → 2) and the other detect
 * a stale base_version and emit a conflict — exactly the intended
 * behaviour.
 */
final class OperationProcessor
{
    /**
     * Process a batch of operations.
     *
     * @param array<int, array<string, mixed>> $operations
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     conflicts: array<int, array<string, mixed>>,
     *     applied_count: int,
     *     rejected_count: int,
     *     conflict_count: int,
     *     replayed_count: int
     * }
     */
    public function process(array $operations, DeviceToken $token): array
    {
        $registry = config('sync.models');
        $results = [];
        $conflicts = [];
        $applied = 0;
        $rejected = 0;
        $conflicted = 0;
        $replayed = 0;

        foreach ($operations as $index => $op) {
            $opResult = $this->processOne($op, $token, $registry, $index);
            $results[] = $opResult;

            match ($opResult['status']) {
                'applied'   => $applied++,
                'replayed'  => $replayed++,
                'noop'      => $applied++,
                'rejected'  => $rejected++,
                'conflicted'=> $conflicted++,
                'tombstoned'=> $rejected++,
                default     => null,
            };

            if ($opResult['status'] === 'conflicted' && isset($opResult['conflict'])) {
                $conflicts[] = $opResult['conflict'];
            }
        }

        return [
            'results'        => $results,
            'conflicts'      => $conflicts,
            'applied_count'  => $applied,
            'rejected_count' => $rejected,
            'conflict_count' => $conflicted,
            'replayed_count' => $replayed,
        ];
    }

    private function processOne(array $op, DeviceToken $token, array $registry, int $index): array
    {
        $opUuid    = $op['op_uuid'] ?? null;
        $modelKey  = $op['model'] ?? null;
        $action    = $op['action'] ?? null;

        // Shape validation. The client should already have ensured this,
        // but defence in depth: a malformed op should not be retried
        // by anyone.
        if (! is_string($opUuid) || ! is_string($modelKey) || ! is_string($action)) {
            return $this->errorResult($opUuid, 'invalid_shape', 'op_uuid, model, and action are required strings.');
        }

        if (! isset($registry[$modelKey])) {
            return $this->errorResult($opUuid, 'unknown_model', "Model '{$modelKey}' is not registered for sync.");
        }

        // Dedupe check. A cached snapshot is the source of truth for a
        // replay; we return it verbatim so the client cannot infer a
        // different outcome.
        $existing = SyncOperationLog::query()
            ->where('device_token_id', $token->id)
            ->where('op_uuid', $opUuid)
            ->first();

        if ($existing !== null) {
            $snapshot = $existing->response_snapshot ?? [];
            $snapshot['status'] = 'replayed';
            $snapshot['op_uuid'] = $opUuid;
            return $snapshot;
        }

        $resolver = $this->resolverFor($modelKey, $registry[$modelKey]);

        // Each op in its own transaction. We MUST not span the whole
        // batch — a single resolver throwing would lose every successful
        // op since the batch's first commit point.
        try {
            $result = DB::transaction(fn () => $resolver->resolve([
                'op_uuid'           => $opUuid,
                'action'            => $action,
                'record_uuid'       => $op['record_uuid'] ?? null,
                'base_version'      => isset($op['base_version']) ? (int) $op['base_version'] : null,
                'fields'            => $op['fields'] ?? [],
                'client_updated_at' => $op['client_updated_at'] ?? null,
            ], $token));
        } catch (\Throwable $e) {
            Log::error('sync.operation.exception', [
                'op_uuid' => $opUuid,
                'model'   => $modelKey,
                'action'  => $action,
                'message' => $e->getMessage(),
            ]);
            return $this->errorResult($opUuid, 'server_error', 'Internal error processing operation.');
        }

        $response = $this->shapeResponse($opUuid, $modelKey, $action, $result);

        // Log to the operation ledger BEFORE returning, so a client
        // retrying a successful op in flight always sees the cached
        // response. Use updateOrInsert so a duplicate insert race
        // (two requests with the same op_uuid landing concurrently)
        // doesn't violate the unique constraint.
        SyncOperationLog::query()->updateOrInsert(
            [
                'device_token_id' => $token->id,
                'op_uuid'         => $opUuid,
            ],
            [
                'model_type'        => $registry[$modelKey]['class'],
                'record_uuid'       => $result->model?->uuid ?? ($op['record_uuid'] ?? null),
                'action'            => $action,
                'status'            => $response['status'],
                'resulting_version' => $result->model?->record_version,
                'response_snapshot' => json_encode($response),
                'processed_at'      => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]
        );

        if ($result->outcome === 'conflicted') {
            $conflict = SyncConflict::create([
                'uuid'                => (string) Str::uuid(),
                'model_type'          => $registry[$modelKey]['class'],
                'record_uuid'         => $op['record_uuid'] ?? null,
                'device_token_id'     => $token->id,
                'user_id'             => $token->user_id,
                'reason'              => $result->reason,
                'server_version'      => $result->model?->record_version,
                'client_base_version' => isset($op['base_version']) ? (int) $op['base_version'] : null,
                'client_payload'      => $op,
                'server_state'        => $result->serverState,
                'error_message'       => $result->errorMessage,
            ]);
            $response['conflict'] = [
                'id'             => $conflict->id,
                'uuid'           => $conflict->uuid,
                'reason'         => $conflict->reason,
                'server_state'   => $conflict->server_state,
                'server_version' => $conflict->server_version,
            ];
        }

        return $response;
    }

    private function resolverFor(string $modelKey, array $config): Resolver
    {
        $class = $config['resolver'];

        // Resolvers with constructor args (model class, key) are
        // instantiated directly. Stateless ones (like
        // WorkOrderStateMachineResolver) come from the container.
        if (in_array($class, [ReadOnlyResolver::class], true)) {
            return new $class($modelKey);
        }

        if (in_array($class, [AppendOnlyResolver::class, \App\Sync\Resolvers\LastWriterWinsResolver::class], true)) {
            return new $class($config['class'], $modelKey);
        }

        return app($class);
    }

    private function shapeResponse(string $opUuid, string $modelKey, string $action, ResolverResult $result): array
    {
        $base = [
            'op_uuid' => $opUuid,
            'model'   => $modelKey,
            'action'  => $action,
            'status'  => $result->outcome,
        ];

        if ($result->model !== null && method_exists($result->model, 'toSyncArray')) {
            $base['record'] = $result->model->toSyncArray();
            $base['record_version'] = (int) $result->model->record_version;
            $base['record_uuid'] = $result->model->uuid;
        }

        if ($result->reason !== null) {
            $base['reason'] = $result->reason;
        }
        if ($result->errorMessage !== null) {
            $base['error'] = $result->errorMessage;
        }

        return $base;
    }

    private function errorResult(?string $opUuid, string $reason, string $message): array
    {
        return [
            'op_uuid' => $opUuid,
            'status'  => 'rejected',
            'reason'  => $reason,
            'error'   => $message,
        ];
    }
}
