<?php

declare(strict_types=1);

namespace App\Sync\Concerns;

use App\Sync\Observers\SyncableObserver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applied to every Eloquent model that participates in the offline-first
 * sync protocol. Three responsibilities:
 *
 *   1. Bind a lifecycle observer that maintains uuid, record_version,
 *      sync_origin, and synced_at columns on every persisted write.
 *
 *   2. Provide query scopes the sync API uses:
 *         - changedSince($cursor) — pull cursor query, indexed.
 *         - byUuid($uuid)         — primary lookup from sync push.
 *
 *   3. Expose syncableAttributes() — the whitelisted projection that goes
 *      out on the wire. By default it is $fillable + a few sync columns,
 *      but models override to add computed fields or hide sensitive ones.
 *
 * NOTE: This trait deliberately does not pull in eloquent timestamps
 * behaviour or soft-delete logic; those are orthogonal and remain owned
 * by the model class. Composing rather than inheriting keeps the existing
 * models' surface area unchanged.
 */
trait Syncable
{
    public static function bootSyncable(): void
    {
        static::observe(SyncableObserver::class);
    }

    /**
     * `initialize<TraitName>` is the Eloquent hook for trait-supplied
     * defaults. We use it to inject datetime casts for the sync columns
     * so `$model->synced_at` is a Carbon (not a string) — otherwise
     * `optional($synced_at)->toIso8601String()` silently returns null
     * and the pull cursor never advances.
     */
    public function initializeSyncable(): void
    {
        $this->casts = array_merge($this->casts ?? [], [
            'synced_at'         => 'datetime',
            'client_updated_at' => 'datetime',
            'record_version'    => 'integer',
        ]);
    }

    /**
     * Pull cursor query. `synced_at` is the canonical cursor column — see
     * the migration header for why this is not `updated_at`.
     *
     * The `> $cursor OR (= $cursor AND id > $lastId)` shape avoids skipped
     * rows at second-boundary collisions on busy tables. The composite
     * (synced_at, id) index makes both halves cheap.
     */
    public function scopeChangedSince(Builder $query, ?string $cursor, ?int $lastId = null): Builder
    {
        if ($cursor === null) {
            return $query->orderBy('synced_at')->orderBy('id');
        }

        return $query
            ->where(function (Builder $q) use ($cursor, $lastId) {
                $q->where('synced_at', '>', $cursor);
                if ($lastId !== null) {
                    $q->orWhere(function (Builder $qq) use ($cursor, $lastId) {
                        $qq->where('synced_at', '=', $cursor)->where('id', '>', $lastId);
                    });
                }
            })
            ->orderBy('synced_at')
            ->orderBy('id');
    }

    public function scopeByUuid(Builder $query, string $uuid): Builder
    {
        return $query->where('uuid', $uuid);
    }

    /**
     * The wire projection. Default: $fillable plus sync metadata. Override
     * on a per-model basis when you need to mask fields (e.g. internal
     * costs) or include derived ones (e.g. computed availability).
     *
     * @return array<string, mixed>
     */
    public function toSyncArray(): array
    {
        $base = $this->only($this->getFillable());

        return array_merge($base, [
            'id'                => $this->getKey(),
            'uuid'              => $this->uuid,
            'record_version'    => (int) $this->record_version,
            'client_updated_at' => optional($this->client_updated_at)->toIso8601String(),
            'sync_origin'       => $this->sync_origin,
            'synced_at'         => optional($this->synced_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
            'created_at'        => optional($this->created_at)->toIso8601String(),
            'deleted_at'        => method_exists($this, 'trashed') && $this->trashed()
                ? optional($this->deleted_at)->toIso8601String()
                : null,
        ]);
    }

    /**
     * Whitelist of fields a sync client is allowed to author. Defaults to
     * $fillable; override on models where sync-clients have narrower
     * authority than admin-form submissions (e.g. operators can update
     * status + produced_quantity on a WO but not planned_quantity).
     *
     * @return array<int, string>
     */
    public function syncWritableFields(): array
    {
        return $this->getFillable();
    }
}
