<?php

declare(strict_types=1);

/**
 * API version router.
 *
 * This file does nothing but mount version files. Each version is a complete,
 * self-contained surface in its own file — when v2 arrives it is added here as
 * a sibling, never as an `if` inside a shared controller. That is what makes
 * "v1 keeps working" a structural property rather than a promise.
 *
 * The `api` prefix comes from bootstrap/app.php (`apiPrefix: 'api'`), so the
 * routes below resolve at /api/v1/...
 *
 * Route caching note: `php artisan route:cache` runs on every deploy, which
 * forbids closures in route definitions. Everything here points at an
 * invokable or a controller method. ApiRouteConventionsTest enforces it.
 */

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api/v1.php'));
