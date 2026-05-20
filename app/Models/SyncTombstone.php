<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * One row per deleted syncable record.
 *
 * Tombstones are append-only and immutable: once written, they participate
 * in pull deltas forever. Old tombstones can be pruned by the SyncJanitor
 * job once every active device has acknowledged synced_at past them, but
 * that is an operational concern, not a data-model one.
 */
class SyncTombstone extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'model_type',
        'uuid',
        'original_id',
        'deleted_by',
        'sync_origin',
        'deleted_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'synced_at'  => 'datetime',
        ];
    }

    /**
     * Record a deletion. Idempotent: re-recording the same (model_type,
     * uuid) is a no-op rather than an error, because the SyncableObserver
     * can fire the `deleted` event more than once across soft-then-force
     * deletes.
     */
    public static function recordDeletion(Model $model): void
    {
        if (empty($model->uuid)) {
            // A model without a uuid can't have been a syncable record by
            // the time we got here. Synth one — the only practical
            // implication is that the tombstone is unreachable from
            // client pulls (no client has the matching uuid to delete).
            // We still record it so admins have an audit trail.
            $model->uuid = (string) Str::uuid();
        }

        $now = now();

        static::query()->updateOrInsert(
            [
                'model_type' => get_class($model),
                'uuid'       => (string) $model->uuid,
            ],
            [
                'original_id'  => $model->getKey(),
                'deleted_by'   => Auth::id(),
                'sync_origin'  => $model->sync_origin ?? 'server',
                'deleted_at'   => $now,
                'synced_at'    => $now,
            ]
        );
    }
}
