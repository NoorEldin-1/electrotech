<?php

declare(strict_types=1);

use App\Http\Controllers\Sync\EnrollController;
use App\Http\Controllers\Sync\PullController;
use App\Http\Controllers\Sync\PushController;
use App\Http\Controllers\Sync\SnapshotController;
use App\Http\Middleware\AuthenticateSyncDevice;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/**
 * Offline-first sync API.
 *
 * Two route groups with distinct auth surfaces:
 *
 *   /sync/enroll
 *     Runs under the web session guard. The operator must be signed in
 *     to the Filament admin panel to mint a new device token. Once
 *     they have the token, they never need session cookies again.
 *
 *   /sync/{pull,push,snapshot,heartbeat}
 *     Authenticated by Bearer token via AuthenticateSyncDevice. No
 *     session, no CSRF — these are stateless API calls. CSRF
 *     exemption is added in bootstrap/app.php.
 *
 * Rate limiting: 60 push/min per device is plenty for steady-state
 * factory work (a fast operator processes ~1 op per 10 seconds);
 * snapshots are 5/hour because they should be once-per-enrolment.
 */

Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    SubstituteBindings::class,
    \Illuminate\Auth\Middleware\Authenticate::class,
])
    ->post('/sync/enroll', EnrollController::class)
    ->name('sync.enroll');

Route::middleware([AuthenticateSyncDevice::class])
    ->prefix('sync')
    ->name('sync.')
    ->group(function () {
        Route::post('/pull', PullController::class)
            ->middleware('throttle:120,1')
            ->name('pull');

        Route::post('/push', PushController::class)
            ->middleware('throttle:60,1')
            ->name('push');

        Route::get('/snapshot', SnapshotController::class)
            ->middleware('throttle:5,60')
            ->name('snapshot');

        // Health check — no DB hit, just confirms the token works.
        // The client uses this to distinguish "weak link but auth OK"
        // from "auth broken, must re-enrol".
        Route::get('/heartbeat', function (\Illuminate\Http\Request $r) {
            return response()->json([
                'ok'          => true,
                'server_time' => now()->toIso8601String(),
            ]);
        })->name('heartbeat');
    });
