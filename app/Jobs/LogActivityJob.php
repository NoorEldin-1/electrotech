<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\QueuedActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Optional queueable wrapper for persisting an activity row.
 *
 * The default {@see QueuedActivityLogger} doesn't use this job — it
 * persists via a `terminating()` callback in the same PHP process, which
 * is reliable without a worker. This job exists for setups that DO want
 * to push activity writes onto a worker (e.g. very high traffic, or to
 * isolate audit-log latency from request handling).
 *
 * Usage:
 *   \App\Jobs\LogActivityJob::dispatch($attributes);
 */
class LogActivityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    public function __construct(
        public readonly array $attributes,
    ) {
        $this->onQueue('activity-log');
    }

    public function handle(): void
    {
        QueuedActivityLogger::persistActivity($this->attributes);
    }
}
