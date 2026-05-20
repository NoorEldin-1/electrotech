<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-operation idempotency ledger.
 *
 * Why this exists in addition to the HTTP-level Idempotency middleware:
 * the sync push endpoint accepts an *array* of operations in a single
 * request, so a single Idempotency-Key would cover the whole batch. If
 * the client retries because, say, half the batch made it through before
 * the connection dropped, we need to dedupe at the per-operation level —
 * not refuse the entire batch — so the remaining ops can still apply.
 *
 * Layout:
 *   - unique(device_token_id, op_uuid) is the dedupe key
 *   - response_snapshot caches the exact response we returned the first
 *     time, so a replay returns byte-identical output without re-running
 *     the handler
 *   - status differentiates `applied` (first run), `replayed` (cache hit),
 *     `rejected` (validation failure), `conflicted` (LWW arbitrated away)
 *     so the admin UI can show meaningful funnels
 */
class SyncOperationLog extends Model
{
    public $table = 'sync_operation_log';

    protected $fillable = [
        'device_token_id',
        'op_uuid',
        'model_type',
        'record_uuid',
        'action',
        'status',
        'resulting_version',
        'response_snapshot',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'response_snapshot' => 'array',
            'processed_at'      => 'datetime',
        ];
    }

    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(DeviceToken::class);
    }
}
