<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sync;

use App\Models\DeviceToken;
use App\Sync\PullCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Snapshot endpoint.
 *
 *   GET /sync/snapshot
 *
 * Returns the initial dataset for a freshly enrolled device, by pulling
 * each model with a null cursor and a generous page size. The response
 * includes the server_time so the client can persist a coherent cursor
 * for incremental pulls afterward.
 *
 * Why this is separate from /pull:
 *   - Different page size policy (bigger pages on snapshot, fewer round
 *     trips at the cost of one slow transfer)
 *   - Different cursor semantics (snapshot ALWAYS starts from zero;
 *     /pull would refuse a stale cursor over max_lag_seconds)
 *   - Lets the controller stream the response (StreamedJsonResponse)
 *     for large initial pulls without buffering everything in memory
 *
 * If the snapshot doesn't fit in one response, the controller emits
 * `next_snapshot_cursor` and the client calls back with `?after=...`
 * until empty.
 */
final class SnapshotController
{
    public function __invoke(Request $request, PullCoordinator $coordinator): JsonResponse
    {
        $data = $request->validate([
            'models'   => ['nullable', 'array'],
            'models.*' => ['string'],
            'after'    => ['nullable', 'array'],
        ]);

        /** @var DeviceToken $token */
        $token = $request->attributes->get('sync.device_token');

        $available    = array_keys(config('sync.models'));
        $modelsToPull = isset($data['models'])
            ? array_values(array_intersect($data['models'], $available))
            : $available;

        // Bigger page size for initial sync — one fat response beats
        // many small ones when the link is bandwidth-bottlenecked but
        // we know the user is patient (they're onboarding the device).
        $pageSize = (int) config('sync.pull.page_size', 100) * 5;

        $deltas = [];
        foreach ($modelsToPull as $modelKey) {
            $cursor = $data['after'][$modelKey] ?? null;
            $deltas[] = $coordinator->pull($modelKey, $token, $cursor, $pageSize);
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'deltas'      => $deltas,
            'is_snapshot' => true,
        ]);
    }
}
