<?php

declare(strict_types=1);

namespace App\Sync\Resolvers;

use App\Models\DeviceToken;

/**
 * Resolves a single client-submitted operation against the current
 * server state. Implementations get the full operation payload (action,
 * record_uuid, base_version, fields, client_updated_at) plus the device
 * token (for permission checks and origin stamping) and return a
 * ResolverResult.
 *
 * Implementations MUST NOT throw on expected outcomes (stale version,
 * permission denied, etc.) — those are returned as `conflicted` or
 * `rejected`. Throwing should be reserved for genuinely unexpected
 * states (DB unreachable, etc.) so the caller can surface them as 5xx.
 *
 * Implementations MUST NOT call DB::beginTransaction themselves — the
 * OperationProcessor wraps each operation in its own transaction so a
 * mid-flight failure rolls back cleanly without contaminating sibling
 * ops in the same batch.
 */
interface Resolver
{
    /**
     * @param array{
     *     op_uuid: string,
     *     action: string,
     *     record_uuid: ?string,
     *     base_version: ?int,
     *     fields: array<string, mixed>,
     *     client_updated_at: ?string
     * } $operation
     */
    public function resolve(array $operation, DeviceToken $token): ResolverResult;
}
