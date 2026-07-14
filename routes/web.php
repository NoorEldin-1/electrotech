<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OfferPdfController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\PurchaseOrderPdfController;
use App\Http\Controllers\QualitySheetPdfController;
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

// UI language switch, driven by the segmented control inside the user-menu
// dropdown. Delegates to filament-language-switch's trigger() (session +
// forever cookie + LocaleChanged event) and redirects back to the referrer.
Route::middleware('auth')
    ->get('/admin/locale/{locale}', LocaleController::class)
    ->whereIn('locale', ['en', 'ar'])
    ->name('admin.locale');

// Printable PDF quotation for a project offer (Slides 7 & 8). Streamed
// through PHP and gated by ProjectOfferPolicy::print (project_offers.print).
Route::middleware('auth')
    ->get('offers/{offer}/pdf', [OfferPdfController::class, 'show'])
    ->name('offers.pdf');

// Printable purchase order document (Purchasing slide 8). Gated by
// PurchaseOrderPolicy::print (purchase_orders.print).
Route::middleware('auth')
    ->get('purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderPdfController::class, 'show'])
    ->name('purchase_orders.pdf');

// Printable quality sheet (Manufacturing slide 3). Gated by
// QualitySheetPolicy::print (quality_sheets.print).
Route::middleware('auth')
    ->get('quality-sheets/{qualitySheet}/pdf', [QualitySheetPdfController::class, 'show'])
    ->name('quality_sheets.pdf');
