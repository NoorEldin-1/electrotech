<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

/**
 * Activity logger that defers the database write until *after* the HTTP
 * response has been flushed to the client, using Laravel's "terminating"
 * callbacks. This keeps the user-perceived response time unaffected
 * (the write happens in the same PHP process, but the user already has
 * their response) and avoids the operational burden of running a queue
 * worker just for audit logging.
 *
 * In non-HTTP contexts (artisan commands, queue workers, tests), the
 * write happens inline — terminating callbacks aren't reliable there.
 */
class QueuedActivityLogger extends ActivityLogger
{
    public function log(string $description): ?ActivityContract
    {
        if ($this->logStatus->disabled()) {
            return null;
        }

        $activity = $this->getActivity();

        $activity->description = $this->replacePlaceholders(
            $activity->description ?? $description,
            $activity
        );

        if (isset($activity->subject) && method_exists($activity->subject, 'tapActivity')) {
            $this->tap([$activity->subject, 'tapActivity'], $activity->event ?? '');
        }

        // Capture the timestamp NOW so the recorded `created_at` matches
        // when the action actually happened, even if the actual insert
        // is deferred to terminating().
        $now = Carbon::now();
        $activity->created_at = $activity->created_at ?? $now;
        $activity->updated_at = $activity->updated_at ?? $now;

        $attributes = $this->serializeAttributes($activity);

        if (static::shouldDeferWrite()) {
            // Register a callback that fires after Laravel sends the
            // HTTP response. The user is already looking at the result
            // by the time this insert runs.
            app()->terminating(function () use ($attributes): void {
                static::persistActivity($attributes);
            });
        } else {
            static::persistActivity($attributes);
        }

        // Reset so the next call starts with a fresh activity instance.
        $this->activity = null;

        return $activity;
    }

    /**
     * Persist a single activity row via a raw insert. We bypass the
     * Eloquent model + its events on purpose:
     *   1. There's nothing to observe on an Activity row.
     *   2. Raw inserts are the cheapest write path.
     *   3. Calling Activity::create() would fire model events that could
     *      themselves trigger logging — an infinite loop risk.
     */
    public static function persistActivity(array $attributes): void
    {
        try {
            $activity = ActivitylogServiceProvider::getActivityModelInstance();

            $now = Carbon::now();
            $attributes['created_at'] = $attributes['created_at'] ?? $now;
            $attributes['updated_at'] = $attributes['updated_at'] ?? $now;

            DB::connection($activity->getConnectionName())
                ->table($activity->getTable())
                ->insert($attributes);
        } catch (\Throwable $e) {
            // Audit logging must never break the calling operation.
            // Failures are reported via the standard log channel so they
            // remain visible without bubbling up.
            Log::warning('Failed to persist activity log entry', [
                'exception' => $e->getMessage(),
                'attributes' => $attributes,
            ]);
        }
    }

    /**
     * In HTTP requests we defer the write to a terminating callback so
     * the user's response is sent first. In CLI processes (artisan,
     * queue workers, tests) terminating() callbacks aren't reliable —
     * write inline instead.
     */
    protected static function shouldDeferWrite(): bool
    {
        return ! app()->runningInConsole();
    }

    /**
     * Convert the Activity model's pending attributes into a plain array
     * suitable for a raw insert — applying Eloquent's casts so the JSON
     * `properties` column is encoded correctly.
     */
    protected function serializeAttributes(ActivityContract $activity): array
    {
        $attributes = $activity->getAttributes();

        // `properties` is cast to Collection on the model; the cast
        // serializer in Eloquent stores it as a JSON string in the
        // attributes array, but we defensively handle Collection /
        // array shapes too in case future Spatie releases change this.
        if (isset($attributes['properties'])) {
            $value = $attributes['properties'];

            if ($value instanceof \Illuminate\Support\Collection) {
                $attributes['properties'] = $value->toJson();
            } elseif (is_array($value)) {
                $attributes['properties'] = json_encode($value);
            }
        } else {
            $attributes['properties'] = json_encode([]);
        }

        return $attributes;
    }
}
