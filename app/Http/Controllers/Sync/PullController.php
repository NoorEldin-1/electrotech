<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sync;

use App\Models\DeviceToken;
use App\Sync\PullCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pull endpoint.
 *
 *   POST /sync/pull
 *   {
 *     "cursors": {
 *       "work_order": { "synced_at": "...", "last_id": 42 },
 *       "item":       null
 *     },
 *     "models": ["work_order", "item", "inventory_transaction"]    // optional filter
 *   }
 *
 *   Response:
 *   {
 *     "server_time": "...",
 *     "deltas": [
 *       { "model": "work_order", "records": [...], "tombstones": [...], "next_cursor": {...}, "has_more": false }
 *     ]
 *   }
 *
 * Why this is one endpoint that pulls every model in parallel rather
 * than N endpoints (one per model): on a 200 kbps link with 500 ms RTT,
 * each round trip costs ~3 seconds of dead air. Bundling cursors per
 * model into a single request saves 7 RTTs on the typical pull.
 *
 * The cursors map is partial: a client can omit a model to skip it, or
 * send null to indicate "first time". Per-model cursors are returned
 * unconditionally so the client can persist them atomically.
 */
final class PullController
{
    public function __invoke(Request $request, PullCoordinator $coordinator): JsonResponse
    {
        // We only validate the *outer* shape; the cursor payload itself
        // is read via $request->input('cursors') so the nested split
        // form (`records.{...}, tombstones.{...}`) is preserved.
        // Laravel's validated() returns only the keys listed in the
        // rules, which would strip the nested structure we need.
        $request->validate([
            'cursors'   => ['nullable', 'array'],
            'cursors.*' => ['nullable', 'array'],
            'models'    => ['nullable', 'array'],
            'models.*'  => ['string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        /** @var DeviceToken $token */
        $token = $request->attributes->get('sync.device_token');
        $cursors  = $request->input('cursors', []) ?? [];
        $filter   = $request->input('models');
        $pageSize = $request->input('page_size');

        $available = array_keys(config('sync.models'));
        $modelsToPull = $filter !== null
            ? array_values(array_intersect($filter, $available))
            : $available;

        $deltas = [];
        foreach ($modelsToPull as $modelKey) {
            $cursor = $cursors[$modelKey] ?? null;
            $deltas[] = $coordinator->pull($modelKey, $token, $cursor, $pageSize);
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'deltas'      => $deltas,
        ]);
    }
}
