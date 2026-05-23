<?php

use App\Http\Controllers\PingController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Network-resilience ping. Lightweight — extends session TTL, lets the
// browser measure RTT and decide whether to surface a "weak link" banner.
//
// Uses Laravel's standard `auth` middleware (not Filament's Authenticate)
// because the latter additionally checks `canAccessPanel`, which would
// reject a freshly created user without a role. Any authenticated user
// should be able to keep their session alive, even if they lack panel
// access  otherwise their session would silently time out while they
// are denied at the panel layer.
Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    SubstituteBindings::class,
])
    ->get('/admin/ping', PingController::class)
    ->name('admin.ping');
