<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\DeviceToken;
use App\Models\SyncTombstone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Computes the pull delta for a given (device, model, cursor) triple.
 *
 * The pull cursor is a composite (synced_at, last_id). Using just
 * synced_at is unsafe: many rows can share the same second on a busy
 * table, and the cursor would skip rows that committed in the same
 * second after the last pull's MAX(id). Carrying last_id breaks ties.
 *
 * Per-model scopes (config/sync.php → pull_scope) are resolved here so
 * the per-user authority filter sits in one place rather than scattered
 * across every controller. A scope returns the *closure name* registered
 * via SyncScope::register() in a service provider, so callers can wire
 * complex authorisation logic without bloating config.
 *
 * Output shape (per model):
 *
 *   {
 *     model: 'work_order',
 *     records: [...],            // up to page_size rows
 *     tombstones: [...],         // tombstones changed since cursor
 *     next_cursor: { synced_at, last_id } | null,
 *     has_more: bool,
 *     force_snapshot: bool       // see config/sync.php pull.max_lag_seconds
 *   }
 */
final class PullCoordinator
{
    public function pull(string $modelKey, DeviceToken $token, ?array $cursor, ?int $pageSize = null): array
    {
        $registry = config('sync.models');
        if (! isset($registry[$modelKey])) {
            return $this->emptyResponse($modelKey, 'unknown_model');
        }

        $cfg = $registry[$modelKey];
        $pageSize = $pageSize ?? (int) config('sync.pull.page_size', 100);
        $maxLag   = (int) config('sync.pull.max_lag_seconds', 7 * 86400);

        $forceSnapshot = $this->shouldForceSnapshot($cursor, $maxLag);

        if ($forceSnapshot) {
            return [
                'model'          => $modelKey,
                'records'        => [],
                'tombstones'     => [],
                'next_cursor'    => null,
                'has_more'       => false,
                'force_snapshot' => true,
            ];
        }

        // Records and tombstones share a logical cursor (`synced_at`)
        // but have independent integer primary keys, so the tie-break
        // `last_id` is tracked separately for each stream. A single
        // shared `last_id` would silently drop a tombstone whose id
        // collides with a previously-seen record id in the same second.
        $recordCursor    = $this->parseStreamCursor($cursor, 'records');
        $tombstoneCursor = $this->parseStreamCursor($cursor, 'tombstones');

        // Normalise the timestamp portion to the DB's native format.
        // Clients send ISO-8601 (`2026-05-20T10:00:00+00:00`) but DBs
        // that compare timestamps by string (SQLite, or a misconfigured
        // MySQL with non-TIMESTAMP columns) won't match against
        // `2026-05-20 10:00:00`. Carbon round-trip fixes both.
        $recordDbCursor    = $this->toDbTimestamp($recordCursor['synced_at']);
        $tombstoneDbCursor = $this->toDbTimestamp($tombstoneCursor['synced_at']);

        $modelClass = $cfg['class'];

        /** @var Builder $query */
        $query = $modelClass::query();
        if (! empty($cfg['eager'])) {
            $query->with($cfg['eager']);
        }
        if (! empty($cfg['includes_soft_deleted']) && method_exists($modelClass, 'withTrashed')) {
            $query->withTrashed();
        }

        // Per-user authority scope, if registered.
        if (! empty($cfg['pull_scope'])) {
            $query = $this->applyScope($query, $cfg['pull_scope'], $token);
        }

        $query->changedSince($recordDbCursor, $recordCursor['last_id']);

        // page_size + 1: cheap "has more" detection without COUNT(*).
        $rows = $query->limit($pageSize + 1)->get();
        $hasMore = $rows->count() > $pageSize;
        $rows = $rows->take($pageSize);

        $records = $rows->map(
            fn (Model $m) => method_exists($m, 'toSyncArray') ? $m->toSyncArray() : $m->toArray()
        )->all();

        // Tombstones for this model since cursor — independent stream.
        $tombstones = SyncTombstone::query()
            ->where('model_type', $modelClass)
            ->when($tombstoneDbCursor, function (Builder $q) use ($tombstoneDbCursor, $tombstoneCursor) {
                $q->where(function (Builder $qq) use ($tombstoneDbCursor, $tombstoneCursor) {
                    $qq->where('synced_at', '>', $tombstoneDbCursor);
                    if ($tombstoneCursor['last_id'] !== null) {
                        $qq->orWhere(function (Builder $qqq) use ($tombstoneDbCursor, $tombstoneCursor) {
                            $qqq->where('synced_at', '=', $tombstoneDbCursor)
                                ->where('id', '>', $tombstoneCursor['last_id']);
                        });
                    }
                });
            })
            ->orderBy('synced_at')
            ->orderBy('id')
            ->limit($pageSize)
            ->get([
                'id', 'model_type', 'uuid', 'original_id', 'sync_origin',
                'deleted_at', 'synced_at',
            ])
            ->map(fn (SyncTombstone $t) => [
                // _tombstone_id is the stream's tie-break for cursor
                // advancement; intentionally prefixed so it doesn't
                // get confused with the deleted record's original_id.
                '_tombstone_id' => $t->id,
                'uuid'          => $t->uuid,
                'original_id'   => $t->original_id,
                'sync_origin'   => $t->sync_origin,
                'deleted_at'    => optional($t->deleted_at)->toIso8601String(),
                'synced_at'     => optional($t->synced_at)->toIso8601String(),
            ])
            ->all();

        // Compute next cursor from whichever (record or tombstone) sits
        // furthest in time. They both feed the same logical cursor.
        $nextCursor = $this->advanceCursor($rows->last(), end($tombstones) ?: null);

        return [
            'model'          => $modelKey,
            'records'        => $records,
            'tombstones'     => $tombstones,
            'next_cursor'    => $nextCursor,
            'has_more'       => $hasMore || count($tombstones) >= $pageSize,
            'force_snapshot' => false,
        ];
    }

    private function applyScope(Builder $query, string $scopeName, DeviceToken $token): Builder
    {
        $scopes = app(SyncScopeRegistry::class);
        $scope = $scopes->resolve($scopeName);
        if ($scope === null) {
            return $query;
        }

        return $scope($query, $token);
    }

    private function shouldForceSnapshot(?array $cursor, int $maxLag): bool
    {
        if ($cursor === null || empty($cursor['synced_at'])) {
            // Initial sync → use snapshot, not pull, for efficiency.
            // But the controller can detect this via cursor === null; we
            // don't second-guess here.
            return false;
        }
        try {
            $cursorAt = \Carbon\Carbon::parse($cursor['synced_at']);
            return $cursorAt->lt(now()->subSeconds($maxLag));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Parse one stream's slice of the per-model cursor.
     *
     * The wire shape supports both the new split form:
     *   { records: { synced_at, last_id }, tombstones: { synced_at, last_id } }
     * and the legacy flat form (still emitted by older clients):
     *   { synced_at, last_id }
     * which is read as the records stream and a null tombstones stream.
     *
     * @return array{synced_at: ?string, last_id: ?int}
     */
    private function parseStreamCursor(?array $cursor, string $stream): array
    {
        if ($cursor === null) {
            return ['synced_at' => null, 'last_id' => null];
        }
        // Split form
        if (isset($cursor[$stream]) && is_array($cursor[$stream])) {
            return [
                'synced_at' => $cursor[$stream]['synced_at'] ?? null,
                'last_id'   => isset($cursor[$stream]['last_id']) ? (int) $cursor[$stream]['last_id'] : null,
            ];
        }
        // Flat / legacy form — treat as the records stream only.
        if ($stream === 'records') {
            return [
                'synced_at' => $cursor['synced_at'] ?? null,
                'last_id'   => isset($cursor['last_id']) ? (int) $cursor['last_id'] : null,
            ];
        }
        return ['synced_at' => null, 'last_id' => null];
    }

    private function toDbTimestamp(?string $iso): ?string
    {
        if ($iso === null) return null;
        try {
            return \Carbon\Carbon::parse($iso)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function advanceCursor(?Model $lastRow, $lastTombstone): ?array
    {
        // Each stream keeps its own (synced_at, last_id) so the next
        // pull can resume each independently without one stream's id
        // accidentally filtering the other.
        $records = [
            'synced_at' => $lastRow ? optional($lastRow->synced_at)->toIso8601String() : null,
            'last_id'   => $lastRow?->getKey(),
        ];

        $tombstones = [
            'synced_at' => $lastTombstone['synced_at'] ?? null,
            'last_id'   => $lastTombstone['_tombstone_id'] ?? null,
        ];

        if ($records['synced_at'] === null && $tombstones['synced_at'] === null) {
            return null;
        }

        return ['records' => $records, 'tombstones' => $tombstones];
    }

    private function emptyResponse(string $modelKey, string $error): array
    {
        return [
            'model'          => $modelKey,
            'records'        => [],
            'tombstones'     => [],
            'next_cursor'    => null,
            'has_more'       => false,
            'force_snapshot' => false,
            'error'          => $error,
        ];
    }
}
