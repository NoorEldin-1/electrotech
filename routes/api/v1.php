<?php

declare(strict_types=1);

/**
 * Electrotech REST API — version 1.
 *
 * Mounted at /api/v1 by routes/api.php. The whole v1 surface lives in this one
 * file so the contract is reviewable at a glance: what exists, what it costs
 * (which throttle), and who may reach it (which middleware).
 *
 * Conventions enforced by tests/Feature/Api/V1/Foundation/RouteConventionsTest:
 *   - no closures (route:cache runs on every deploy)
 *   - every route carries a throttle
 *   - every route except the explicitly public ones carries auth:sanctum
 *
 * Module status is tracked in API_PROGRESS.md. Modules 2-11 are appended below
 * as they ship; the ordering of the sections follows the dependency order in
 * API_Development_Plan.md §5.
 */

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\DeviceController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Identity\PermissionController;
use App\Http\Controllers\Api\V1\Identity\RoleController;
use App\Http\Controllers\Api\V1\Identity\UserController;
use App\Http\Controllers\Api\V1\Meta\MetaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public — no token required
|--------------------------------------------------------------------------
|
| Only two things live here, and both are deliberate:
|   - `meta` is the liveness/version probe the app calls before it has a token
|   - `auth/login` is where a token comes from
|
| Anything else added here needs a written reason in API_PROGRESS.md.
*/

Route::middleware('throttle:api-read')->group(function (): void {
    Route::get('meta', [MetaController::class, 'index'])->name('meta');
});

Route::middleware('throttle:api-auth')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
|
| `auth:sanctum` resolves the bearer token to a user. Authorization beyond
| that is per-endpoint, via the same policies the Filament panel uses.
*/

Route::middleware('auth:sanctum')->group(function (): void {

    /*
    | Module 1 — Authentication & session lifecycle
    |
    | These stay on the read limiter rather than the write limiter: a client
    | whose token just expired must be able to rotate it even if it has burned
    | its write quota, otherwise a burst of writes locks the user out.
    */
    Route::middleware('throttle:api-read')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout_all');
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');

        Route::get('auth/me', [ProfileController::class, 'show'])->name('auth.me');

        Route::get('auth/devices', [DeviceController::class, 'index'])->name('auth.devices.index');
        Route::delete('auth/devices/{device}', [DeviceController::class, 'destroy'])
            ->whereNumber('device')
            ->name('auth.devices.destroy');

        Route::get('meta/enums', [MetaController::class, 'enums'])->name('meta.enums');
    });

    Route::middleware('throttle:api-write')->group(function (): void {
        Route::patch('auth/profile', [ProfileController::class, 'update'])->name('auth.profile.update');
        Route::post('auth/change-password', [ProfileController::class, 'changePassword'])
            ->name('auth.change_password');
    });

    /*
    | Module 1 — Identity administration
    |
    | Gated by users.* / roles.manage through UserPolicy and RolePolicy. The
    | `identity` token ability lets a device be issued a token that can read
    | business data but never touch accounts.
    */
    Route::middleware('ability:identity')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])
                ->whereNumber('user')
                ->name('users.show');

            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/{role}', [RoleController::class, 'show'])
                ->whereNumber('role')
                ->name('roles.show');

            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [UserController::class, 'update'])
                ->whereNumber('user')
                ->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])
                ->whereNumber('user')
                ->name('users.destroy');

            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::patch('roles/{role}', [RoleController::class, 'update'])
                ->whereNumber('role')
                ->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])
                ->whereNumber('role')
                ->name('roles.destroy');
        });
    });
});
