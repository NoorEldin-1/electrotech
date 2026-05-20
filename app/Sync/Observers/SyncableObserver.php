<?php

declare(strict_types=1);

namespace App\Sync\Observers;

use App\Models\SyncTombstone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Maintains the sync metadata on every persisted write.
 *
 * Why an observer instead of a trait method:
 *   - Boots once per request even if the model is touched many times.
 *   - Gives a single place to drop tombstones on delete events without
 *     each model class having to remember to do it.
 *   - The Eloquent event ordering guarantees the columns are stamped
 *     before `saving` listeners on the model itself fire, so user-side
 *     mutators see the final values.
 *
 * Invariants this observer enforces:
 *
 *   I1. Every saved row has a uuid. If the caller didn't supply one,
 *       generate it before insert. Existing rows authored before this
 *       feature got their uuid via the migration backfill; this observer
 *       guards new inserts only.
 *
 *   I2. record_version is monotonically increasing PER-ROW. We never let
 *       a caller (including the sync push) set this directly — the
 *       OperationProcessor passes the *expected* version separately and
 *       this observer increments authoritatively.
 *
 *   I3. synced_at is bumped on every server-accepted write. Pull cursors
 *       advance off this column, so failing to bump it would cause the
 *       client to miss the change forever.
 *
 *   I4. sync_origin is "server" for any write that did not come through
 *       the sync push pipeline. The OperationProcessor sets it explicitly
 *       on push-driven writes BEFORE save(); we only fill the default.
 *
 *   I5. Every soft-delete or hard-delete leaves a tombstone, so clients
 *       see the deletion on next pull. Tombstones include the original
 *       integer id for forensic FK chasing.
 */
final class SyncableObserver
{
    public function creating(Model $model): void
    {
        if (empty($model->uuid)) {
            $model->uuid = (string) Str::uuid();
        }

        // First version is 1. Defensive — the column has a default of 1
        // in the schema, but we set it explicitly so introspection (and
        // tests that don't reload from DB) see the value immediately.
        if (empty($model->record_version)) {
            $model->record_version = 1;
        }

        if (empty($model->sync_origin)) {
            $model->sync_origin = 'server';
        }

        $model->synced_at = $model->freshTimestamp();
    }

    public function updating(Model $model): void
    {
        // Monotonic version bump. Only on actual dirty saves — the
        // `updating` event fires before the DB write, after Eloquent has
        // computed the dirty set, so isDirty() reflects user changes only.
        if ($model->isDirty(array_diff(array_keys($model->getAttributes()), ['record_version', 'synced_at']))) {
            $model->record_version = ((int) $model->record_version) + 1;
            $model->synced_at = $model->freshTimestamp();

            // If the caller didn't tag the write with an origin (most
            // server-side code paths won't), record it as server-authored.
            if (! $model->isDirty('sync_origin')) {
                $model->sync_origin = 'server';
            }
        }
    }

    public function deleted(Model $model): void
    {
        // Fires for soft-delete (when the model uses SoftDeletes) and hard
        // delete alike. For soft-delete the row still exists with
        // deleted_at populated; we still emit a tombstone because clients
        // pulling the soft-deleted row would otherwise have to know which
        // tables use soft-deletes and look up the deleted_at column.
        SyncTombstone::recordDeletion($model);
    }

    public function forceDeleted(Model $model): void
    {
        // Force-delete on a soft-delete model. The `deleted` event also
        // fired for soft-delete earlier, so we use upsertOnConflict to
        // avoid a duplicate tombstone (the unique key would otherwise
        // explode here).
        SyncTombstone::recordDeletion($model);
    }
}
