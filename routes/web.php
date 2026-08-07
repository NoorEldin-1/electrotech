<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\GeneralLedgerPdfController;
use App\Http\Controllers\JournalDaybookPdfController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OfferPdfController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\PurchaseOrderPdfController;
use App\Http\Controllers\QualitySheetPdfController;
use App\Http\Controllers\WorkOrderMaterialVariancePdfController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Public system documentation (دليل المنصة). Intentionally NOT behind auth:
// the link is shared with people who have no account, and the page renders
// hand-written explanatory content only — it reads no business data.
Route::get('/documentation', DocumentationController::class)->name('documentation');

// Short, memorable alias so `/docs` also works when the link is typed by hand.
Route::redirect('/docs', '/documentation', 301);

// Generated REST API reference for client developers (Scribe). Public by
// design: it describes the contract, not the data, and the link is shared
// with the mobile developer.
//
// The files are static under public/api/docs/, so the web server normally
// answers this without PHP. The route is the fallback for a server that does
// not resolve `/api/docs` to `/api/docs/index.html` on its own.
//
// NOTE the path: `/docs` above is the Arabic end-user manual — a different
// document for a different audience. Do not merge the two.
Route::get('/api/docs', ApiDocsController::class)->name('api.docs');

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

// Printable analytical daybook (قائمة المواد سلايد 2). Gated by
// journal_daybook.view inside the controller.
Route::middleware('auth')
    ->get('finance/daybook/pdf', JournalDaybookPdfController::class)
    ->name('finance.daybook.pdf');

// Printable account statement / general ledger (قائمة المواد سلايد 3). Gated
// by general_ledger.view inside the controller.
Route::middleware('auth')
    ->get('finance/general-ledger/pdf', GeneralLedgerPdfController::class)
    ->name('finance.general_ledger.pdf');

// Printable planned-vs-issued material comparison for a work order
// (قائمة المواد سلايد 1). Gated by WorkOrderPolicy::view.
Route::middleware('auth')
    ->get('work-orders/{workOrder}/material-variance/pdf', WorkOrderMaterialVariancePdfController::class)
    ->name('work_orders.material_variance.pdf');
