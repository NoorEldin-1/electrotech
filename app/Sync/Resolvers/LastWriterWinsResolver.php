<?php

declare(strict_types=1);

namespace App\Sync\Resolvers;

use App\Models\DeviceToken;
use App\Models\SyncTombstone;
use Illuminate\Database\Eloquent\Model;

/**
 * Last-writer-wins resolver with monotonic version arbitration.
 *
 * Algorithm:
 *   1. If a tombstone exists for the record_uuid → tombstoned.
 *   2. Look up the existing row by uuid.
 *   3. If it does not exist and action == 'upsert' → create it. Newly
 *      authored records have no contended base; the first writer wins.
 *   4. If it exists and the client's base_version === server's
 *      record_version → apply the write. The version bump is handled
 *      by SyncableObserver::updating.
 *   5. If base_version is null and the action is 'upsert', the client
 *      is creating "blind" — treat as new record only if no row exists;
 *      otherwise reject as version_stale.
 *   6. If base_version < server's record_version → conflict.
 *
 * The "fields" portion of the payload is filtered through
 * syncWritableFields() so a client can't widen its write surface by
 * including extra keys.
 */
class LastWriterWinsResolver implements Resolver
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $modelKey,
    ) {}

    public function resolve(array $operation, DeviceToken $token): ResolverResult
    {
        $modelClass  = $this->modelClass;
        $action      = $operation['action'] ?? 'upsert';
        $uuid        = $operation['record_uuid'] ?? null;
        $baseVersion = $operation['base_version'] ?? null;
        $fields      = $operation['fields'] ?? [];

        if ($uuid === null) {
            return ResolverResult::rejected('validation_failed', 'record_uuid is required.');
        }

        if (SyncTombstone::query()->where('model_type', $modelClass)->where('uuid', $uuid)->exists()) {
            return ResolverResult::tombstoned($modelClass, $uuid);
        }

        /** @var Model|null $row */
        $row = $modelClass::query()->where('uuid', $uuid)->first();

        if ($action === 'delete') {
            if ($row === null) {
                return ResolverResult::tombstoned($modelClass, $uuid);
            }
            // Delete is unconditional once the client confirmed the
            // intent — operators who can delete must own the workflow.
            // If the row was modified mid-flight, the conflict was on
            // the user side, not at this layer.
            $row->delete();
            return ResolverResult::applied($row);
        }

        // upsert
        $writable = $this->filterWritable($modelClass, $fields);

        if ($row === null) {
            // First-time create.
            try {
                /** @var Model $row */
                $row = new $modelClass();
                $row->uuid = $uuid;
                $row->sync_origin = $token->device_id;
                $row->client_updated_at = $operation['client_updated_at'] ?? null;
                $row->fill($writable);
                $row->save();
                return ResolverResult::applied($row);
            } catch (\Throwable $e) {
                return ResolverResult::rejected('validation_failed', $e->getMessage());
            }
        }

        $serverVersion = (int) $row->record_version;

        if ($baseVersion === null) {
            // Client did not declare a base; treat as stale unless they
            // happen to be writing to the initial version 1 row with
            // no real changes (rare).
            return ResolverResult::conflicted($row, 'version_stale');
        }

        if ((int) $baseVersion < $serverVersion) {
            return ResolverResult::conflicted($row, 'version_stale');
        }

        // base_version >= serverVersion. base_version > serverVersion
        // should never happen if the client follows the protocol; we
        // still treat it as a successful write to be liberal — only the
        // server can mint version numbers, so apply and the observer
        // increments to current+1.
        try {
            $row->sync_origin       = $token->device_id;
            $row->client_updated_at = $operation['client_updated_at'] ?? null;
            $row->fill($writable);
            if (! $row->isDirty()) {
                // No-op write (e.g. resubmit with same values). Mark as
                // noop so the client clears the queued op without
                // pretending we did work.
                return ResolverResult::noop($row);
            }
            $row->save();
            return ResolverResult::applied($row);
        } catch (\Throwable $e) {
            return ResolverResult::rejected('validation_failed', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function filterWritable(string $modelClass, array $fields): array
    {
        /** @var Model $proto */
        $proto = new $modelClass();
        $allowed = method_exists($proto, 'syncWritableFields')
            ? $proto->syncWritableFields()
            : $proto->getFillable();

        return array_intersect_key($fields, array_flip($allowed));
    }
}
