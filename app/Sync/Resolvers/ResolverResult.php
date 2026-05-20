<?php

declare(strict_types=1);

namespace App\Sync\Resolvers;

use Illuminate\Database\Eloquent\Model;

/**
 * Outcome of a resolver evaluation. Immutable value object so the caller
 * can pattern-match cleanly without inspecting nullable model state.
 *
 * Five distinct outcomes:
 *
 *   applied
 *     The write was accepted and persisted. $model is the post-save row.
 *
 *   conflicted
 *     The server has a newer version. $reason carries the discriminator
 *     ('version_stale', 'illegal_transition', etc.). $model is the
 *     server's authoritative state (NOT the rejected payload). The push
 *     handler logs a SyncConflict row and tells the client to discard.
 *
 *   rejected
 *     The payload is malformed or violates a hard server invariant
 *     (validation, FK miss, permission denied). The client should not
 *     retry without user intervention.
 *
 *   noop
 *     The write is harmless and need not be applied — typically because
 *     the client is trying to set a state that's already true. The
 *     client should mark the op done and move on.
 *
 *   tombstoned
 *     The record was deleted on the server while the client was offline.
 *     The client should drop its local copy and the queued op.
 */
final class ResolverResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?Model $model,
        public readonly ?string $reason,
        public readonly ?string $errorMessage,
        public readonly ?array $serverState,
    ) {}

    public static function applied(Model $model): self
    {
        return new self('applied', $model, null, null, null);
    }

    public static function noop(Model $model): self
    {
        return new self('noop', $model, null, null, null);
    }

    public static function conflicted(Model $serverState, string $reason): self
    {
        return new self(
            'conflicted',
            $serverState,
            $reason,
            null,
            method_exists($serverState, 'toSyncArray') ? $serverState->toSyncArray() : $serverState->toArray()
        );
    }

    public static function rejected(string $reason, string $message): self
    {
        return new self('rejected', null, $reason, $message, null);
    }

    public static function tombstoned(string $modelType, string $uuid): self
    {
        return new self('tombstoned', null, 'tombstoned', "{$modelType} {$uuid} was deleted on the server", null);
    }
}
