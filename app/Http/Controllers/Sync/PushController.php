<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sync;

use App\Models\DeviceToken;
use App\Sync\OperationProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Push endpoint.
 *
 *   POST /sync/push
 *   {
 *     "operations": [
 *       {
 *         "op_uuid": "...",                 // client-minted, per-op idempotency key
 *         "model":   "work_order",          // wire identifier from sync.php
 *         "action":  "transition",          // upsert | delete | transition
 *         "record_uuid":  "...",            // target record (omit only when authoring fresh)
 *         "base_version": 3,                // server's record_version at time of client write
 *         "client_updated_at": "...",       // ISO 8601, audit-only
 *         "fields": { ... }                 // partial state
 *       },
 *       ...
 *     ]
 *   }
 *
 *   Response:
 *   {
 *     "server_time": "...",
 *     "results": [ { op_uuid, status, ... }, ... ],
 *     "conflicts": [ { id, uuid, reason, server_state, server_version }, ... ],
 *     "counters": { applied, rejected, conflicted, replayed }
 *   }
 *
 * The push endpoint is per-operation idempotent (see OperationProcessor)
 * AND wrapped in the global Idempotency middleware — both layers are
 * deliberate. The middleware deduplicates whole-request retries (an
 * exact resubmit of the same batch); the per-op log deduplicates
 * partial retries (different batches sharing some op_uuids).
 */
final class PushController
{
    public function __invoke(Request $request, OperationProcessor $processor): Response
    {
        $maxOps   = (int) config('sync.push.max_operations_per_batch', 200);
        $maxBytes = (int) config('sync.push.max_payload_bytes', 2 * 1024 * 1024);

        // Cheap defence against an oversized payload. The framework will
        // also reject this at php.ini's post_max_size, but failing here
        // gives the client a structured response instead of a bare 413.
        if (strlen((string) $request->getContent()) > $maxBytes) {
            return response()->json([
                'error'   => 'payload_too_large',
                'message' => "Push payload exceeds {$maxBytes} bytes.",
            ], 413);
        }

        $data = $request->validate([
            'operations'                 => ['required', 'array', 'min:1', 'max:' . $maxOps],
            'operations.*.op_uuid'       => ['required', 'string', 'size:36'],
            'operations.*.model'         => ['required', 'string'],
            'operations.*.action'        => ['required', 'string', 'in:upsert,delete,transition'],
            'operations.*.record_uuid'   => ['nullable', 'string', 'size:36'],
            'operations.*.base_version'  => ['nullable', 'integer', 'min:1'],
            'operations.*.fields'        => ['nullable', 'array'],
            'operations.*.client_updated_at' => ['nullable', 'string'],
        ]);

        /** @var DeviceToken $token */
        $token = $request->attributes->get('sync.device_token');

        $outcome = $processor->process($data['operations'], $token);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'results'     => $outcome['results'],
            'conflicts'   => $outcome['conflicts'],
            'counters'    => [
                'applied'    => $outcome['applied_count'],
                'rejected'   => $outcome['rejected_count'],
                'conflicted' => $outcome['conflict_count'],
                'replayed'   => $outcome['replayed_count'],
            ],
        ]);
    }
}
