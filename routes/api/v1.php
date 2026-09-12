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
use App\Http\Controllers\Api\V1\Delivery\DeliveryMinuteController;
use App\Http\Controllers\Api\V1\Delivery\DeliveryVoucherController;
use App\Http\Controllers\Api\V1\Delivery\InstallationController;
use App\Http\Controllers\Api\V1\Delivery\SiteSurveyController;
use App\Http\Controllers\Api\V1\Finance\AccountController;
use App\Http\Controllers\Api\V1\Finance\AccountEntryController;
use App\Http\Controllers\Api\V1\Finance\CostCenterClosingController;
use App\Http\Controllers\Api\V1\Finance\CreditFacilityController;
use App\Http\Controllers\Api\V1\Finance\FinancialClaimController;
use App\Http\Controllers\Api\V1\Finance\JournalEntryController;
use App\Http\Controllers\Api\V1\Finance\OperationPaymentController;
use App\Http\Controllers\Api\V1\Finance\SalesInvoiceController;
use App\Http\Controllers\Api\V1\Identity\PermissionController;
use App\Http\Controllers\Api\V1\Identity\RoleController;
use App\Http\Controllers\Api\V1\Identity\UserController;
use App\Http\Controllers\Api\V1\MasterData\AttachmentController;
use App\Http\Controllers\Api\V1\MasterData\CustomerController;
use App\Http\Controllers\Api\V1\MasterData\ItemController;
use App\Http\Controllers\Api\V1\MasterData\SupplierController;
use App\Http\Controllers\Api\V1\Inventory\AdditionVoucherController;
use App\Http\Controllers\Api\V1\Inventory\DepreciationVoucherController;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Controllers\Api\V1\Manufacturing\IssueVoucherController;
use App\Http\Controllers\Api\V1\Manufacturing\ProductionEntryController;
use App\Http\Controllers\Api\V1\Manufacturing\QualitySheetController;
use App\Http\Controllers\Api\V1\Manufacturing\ReturnVoucherController;
use App\Http\Controllers\Api\V1\Manufacturing\WorkOrderController;
use App\Http\Controllers\Api\V1\Meta\MetaController;
use App\Http\Controllers\Api\V1\Platform\ActivityLogController;
use App\Http\Controllers\Api\V1\Platform\DashboardController;
use App\Http\Controllers\Api\V1\Platform\NotificationController;
use App\Http\Controllers\Api\V1\Platform\SearchController;
use App\Http\Controllers\Api\V1\Reports\DocumentController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\Procurement\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Procurement\StockReservationController;
use App\Http\Controllers\Api\V1\Sales\OfferController;
use App\Http\Controllers\Api\V1\Sales\PipelineController;
use App\Http\Controllers\Api\V1\Sales\ProjectController;
use App\Http\Controllers\Api\V1\TechnicalOffice\BomController;
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
    | Module 2 — Master Data & Files
    |
    | Items, customers, suppliers and the generic attachments endpoint.
    | Everything from Module 3 onward references these, so a client typically
    | caches the three catalogues once per session and refreshes them with
    | `updated_after`.
    |
    | The `master-data` ability lets a device hold a token that reads the
    | catalogue without being able to touch identity or finance.
    */
    Route::middleware('ability:master-data')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('items', [ItemController::class, 'index'])->name('items.index');
            Route::get('items/{item}', [ItemController::class, 'show'])
                ->whereNumber('item')
                ->name('items.show');

            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])
                ->whereNumber('customer')
                ->name('customers.show');

            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
                ->whereNumber('supplier')
                ->name('suppliers.show');

            Route::get('attachments', [AttachmentController::class, 'index'])->name('attachments.index');

            // The download streams a file body rather than the JSON envelope.
            // It stays on the read limiter: a client opening a project folder
            // legitimately pulls several documents in a row.
            Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
                ->whereNumber('attachment')
                ->name('attachments.download');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('items', [ItemController::class, 'store'])->name('items.store');
            Route::patch('items/{item}', [ItemController::class, 'update'])
                ->whereNumber('item')
                ->name('items.update');
            Route::delete('items/{item}', [ItemController::class, 'destroy'])
                ->whereNumber('item')
                ->name('items.destroy');

            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::patch('customers/{customer}', [CustomerController::class, 'update'])
                ->whereNumber('customer')
                ->name('customers.update');
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
                ->whereNumber('customer')
                ->name('customers.destroy');

            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])
                ->whereNumber('supplier')
                ->name('suppliers.update');
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])
                ->whereNumber('supplier')
                ->name('suppliers.destroy');

            Route::post('attachments', [AttachmentController::class, 'store'])->name('attachments.store');
            Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])
                ->whereNumber('attachment')
                ->name('attachments.destroy');
        });
    });

    /*
    | Module 3 — Sales & CRM
    |
    | Operations, the pipeline transitions between their stages, and the
    | offers/BOQ behind each one.
    |
    | The transitions are POSTs rather than a status field on PATCH: each one
    | carries its own permission and its own pre-conditions, and a state
    | machine expressed as an editable column is a state machine that gets
    | bypassed.
    */
    Route::middleware('ability:sales')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('projects/{project}', [ProjectController::class, 'show'])
                ->whereNumber('project')
                ->name('projects.show');

            Route::get('projects/{project}/offers', [OfferController::class, 'index'])
                ->whereNumber('project')
                ->name('projects.offers.index');
            Route::get('offers/{offer}', [OfferController::class, 'show'])
                ->whereNumber('offer')
                ->name('offers.show');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::patch('projects/{project}', [ProjectController::class, 'update'])
                ->whereNumber('project')
                ->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])
                ->whereNumber('project')
                ->name('projects.destroy');

            // Pipeline transitions. Named after the move, not after the target
            // status, so the client reads as the business does.
            Route::prefix('projects/{project}')->whereNumber('project')->group(function (): void {
                Route::post('move-to-tender', [PipelineController::class, 'moveToTender'])
                    ->name('projects.move_to_tender');
                Route::post('move-to-in-hand', [PipelineController::class, 'moveToInHand'])
                    ->name('projects.move_to_in_hand');
                Route::post('move-to-active', [PipelineController::class, 'moveToActive'])
                    ->name('projects.move_to_active');
                Route::post('manager-approve', [PipelineController::class, 'managerApprove'])
                    ->name('projects.manager_approve');
                Route::post('cancel-to-lost', [PipelineController::class, 'cancelToLost'])
                    ->name('projects.cancel_to_lost');

                Route::put('alarm', [PipelineController::class, 'setAlarm'])
                    ->name('projects.alarm.set');
                Route::delete('alarm', [PipelineController::class, 'clearAlarm'])
                    ->name('projects.alarm.clear');

                Route::post('offers', [OfferController::class, 'store'])
                    ->name('projects.offers.store');
            });

            Route::patch('offers/{offer}', [OfferController::class, 'update'])
                ->whereNumber('offer')
                ->name('offers.update');

            // PUT, not PATCH: the whole bill of quantities is replaced in one
            // atomic request. See OfferController::replaceBoq.
            Route::put('offers/{offer}/boq', [OfferController::class, 'replaceBoq'])
                ->whereNumber('offer')
                ->name('offers.boq.replace');

            Route::delete('offers/{offer}', [OfferController::class, 'destroy'])
                ->whereNumber('offer')
                ->name('offers.destroy');
        });
    });

    /*
    | Module 4 — Technical Office / PMO
    |
    | Bills of materials, project-scoped and standard.
    |
    | `boms/{bom}/approve` is separate from the edit routes because it carries a
    | different permission: whoever drafts a BOM is not thereby entitled to
    | commit the company to buying it.
    */
    Route::middleware('ability:technical-office')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('boms', [BomController::class, 'index'])->name('boms.index');
            Route::get('boms/{bom}', [BomController::class, 'show'])
                ->whereNumber('bom')
                ->name('boms.show');

            // The recipe a work order for this product would be planned from.
            Route::get('items/{item}/standard-bom', [BomController::class, 'standardForItem'])
                ->whereNumber('item')
                ->name('items.standard_bom');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('boms', [BomController::class, 'store'])->name('boms.store');
            Route::patch('boms/{bom}', [BomController::class, 'update'])
                ->whereNumber('bom')
                ->name('boms.update');
            Route::put('boms/{bom}/items', [BomController::class, 'replaceItems'])
                ->whereNumber('bom')
                ->name('boms.items.replace');
            Route::post('boms/{bom}/submit', [BomController::class, 'submit'])
                ->whereNumber('bom')
                ->name('boms.submit');
            Route::post('boms/{bom}/approve', [BomController::class, 'approve'])
                ->whereNumber('bom')
                ->name('boms.approve');
            Route::delete('boms/{bom}', [BomController::class, 'destroy'])
                ->whereNumber('bom')
                ->name('boms.destroy');
        });
    });

    /*
    | Module 5 - Procurement
    |
    | Purchase orders and the stock reservations that hold material for an
    | operation.
    |
    | `receive` deliberately answers with an ADDITION VOUCHER, not the order:
    | receiving raises and posts that voucher, and its number is what the
    | warehouse writes on the paperwork.
    */
    Route::middleware('ability:procurement')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])
                ->name('purchase_orders.index');
            Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])
                ->whereNumber('purchase_order')
                ->name('purchase_orders.show');

            Route::get('stock-reservations', [StockReservationController::class, 'index'])
                ->name('stock_reservations.index');
            Route::get('stock-reservations/{stock_reservation}', [StockReservationController::class, 'show'])
                ->whereNumber('stock_reservation')
                ->name('stock_reservations.show');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])
                ->name('purchase_orders.store');
            Route::patch('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update'])
                ->whereNumber('purchase_order')
                ->name('purchase_orders.update');
            Route::put('purchase-orders/{purchase_order}/items', [PurchaseOrderController::class, 'replaceItems'])
                ->whereNumber('purchase_order')
                ->name('purchase_orders.items.replace');
            Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])
                ->whereNumber('purchase_order')
                ->name('purchase_orders.approve');
            Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])
                ->whereNumber('purchase_order')
                ->name('purchase_orders.receive');
            Route::delete('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy'])
                ->whereNumber('purchase_order')
                ->name('purchase_orders.destroy');

            Route::post('stock-reservations', [StockReservationController::class, 'store'])
                ->name('stock_reservations.store');
            Route::post('stock-reservations/{stock_reservation}/release', [StockReservationController::class, 'release'])
                ->whereNumber('stock_reservation')
                ->name('stock_reservations.release');
            Route::post('projects/{project}/reserve-approved-bom', [StockReservationController::class, 'reserveApprovedBom'])
                ->whereNumber('project')
                ->name('projects.reserve_approved_bom');
        });
    });

    /*
    | Module 6 - Inventory & Warehouse
    |
    | Balances, the stock ledger, the item stock card, and the two vouchers that
    | move stock without a work order: goods receipt and loss write-off.
    |
    | Balances and the ledger are READ-ONLY. Stock moves because a document was
    | posted, never because a balance was written - a writable balance could put
    | the ledger and the on-hand figure out of step with nothing to reconcile
    | them against.
    |
    | Issue and return vouchers are NOT here: both are raised against a work
    | order and validated against its remaining material requirement, so they
    | ship with Module 7 (API_Development_Plan.md section 5).
    */
    Route::middleware('ability:inventory')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('inventory', [InventoryController::class, 'levels'])->name('inventory.levels');
            Route::get('inventory-transactions', [InventoryController::class, 'transactions'])
                ->name('inventory.transactions');

            Route::get('addition-vouchers', [AdditionVoucherController::class, 'index'])
                ->name('addition_vouchers.index');
            Route::get('addition-vouchers/{addition_voucher}', [AdditionVoucherController::class, 'show'])
                ->whereNumber('addition_voucher')
                ->name('addition_vouchers.show');

            Route::get('depreciation-vouchers', [DepreciationVoucherController::class, 'index'])
                ->name('depreciation_vouchers.index');
            Route::get('depreciation-vouchers/{depreciation_voucher}', [DepreciationVoucherController::class, 'show'])
                ->whereNumber('depreciation_voucher')
                ->name('depreciation_vouchers.show');
        });

        // The stock card walks an item's whole movement history, so it sits on
        // the reports limiter rather than the general read one.
        Route::middleware('throttle:api-reports')->group(function (): void {
            Route::get('items/{item}/stock-card', [InventoryController::class, 'stockCard'])
                ->whereNumber('item')
                ->name('items.stock_card');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('addition-vouchers', [AdditionVoucherController::class, 'store'])
                ->name('addition_vouchers.store');
            Route::post('addition-vouchers/{addition_voucher}/post', [AdditionVoucherController::class, 'post'])
                ->whereNumber('addition_voucher')
                ->name('addition_vouchers.post');
            Route::post('addition-vouchers/{addition_voucher}/invoice', [AdditionVoucherController::class, 'recordInvoice'])
                ->whereNumber('addition_voucher')
                ->name('addition_vouchers.invoice');
            Route::post('addition-vouchers/{addition_voucher}/close', [AdditionVoucherController::class, 'close'])
                ->whereNumber('addition_voucher')
                ->name('addition_vouchers.close');
            Route::delete('addition-vouchers/{addition_voucher}', [AdditionVoucherController::class, 'destroy'])
                ->whereNumber('addition_voucher')
                ->name('addition_vouchers.destroy');

            Route::post('depreciation-vouchers', [DepreciationVoucherController::class, 'store'])
                ->name('depreciation_vouchers.store');
            Route::patch('depreciation-vouchers/{depreciation_voucher}', [DepreciationVoucherController::class, 'update'])
                ->whereNumber('depreciation_voucher')
                ->name('depreciation_vouchers.update');
            Route::post('depreciation-vouchers/{depreciation_voucher}/post', [DepreciationVoucherController::class, 'post'])
                ->whereNumber('depreciation_voucher')
                ->name('depreciation_vouchers.post');
            Route::delete('depreciation-vouchers/{depreciation_voucher}', [DepreciationVoucherController::class, 'destroy'])
                ->whereNumber('depreciation_voucher')
                ->name('depreciation_vouchers.destroy');
        });
    });

    /*
    | Module 7 - Manufacturing & Material Movement
    |
    | Manufacturing orders and everything that moves material because of one:
    | the issue voucher out of the raw store, the return voucher back into it,
    | the quality sheet, and the read-only production record.
    |
    | Every state change is its own POST with its own permission, never a
    | `status` on PATCH. The order's chain is approve-order (PMO) -> start ->
    | submit-qa -> approve-qa -> finish-manufacturing -> complete, and
    | finish-manufacturing needs BOTH approvals already on the record.
    |
    | Two endpoints sit on the reports limiter rather than the read one because
    | each walks a whole order's voucher history: the material variance report,
    | and the material requirement that an issue voucher is validated against.
    |
    | Issue and return vouchers live here rather than with Module 6's warehouse
    | documents because both are raised against a work order and validated
    | against its remaining material requirement - shipping them earlier would
    | have meant shipping them untestable (API_Development_Plan.md section 5).
    */
    Route::middleware('ability:manufacturing')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('work-orders', [WorkOrderController::class, 'index'])
                ->name('work_orders.index');
            Route::get('work-orders/{work_order}', [WorkOrderController::class, 'show'])
                ->whereNumber('work_order')
                ->name('work_orders.show');

            Route::get('issue-vouchers', [IssueVoucherController::class, 'index'])
                ->name('issue_vouchers.index');
            Route::get('issue-vouchers/{issue_voucher}', [IssueVoucherController::class, 'show'])
                ->whereNumber('issue_voucher')
                ->name('issue_vouchers.show');

            // The excess preview answers the same question the posting gate
            // asks, so a client can warn before the user commits rather than
            // after they are refused.
            Route::get('issue-vouchers/{issue_voucher}/excess', [IssueVoucherController::class, 'excessPreview'])
                ->whereNumber('issue_voucher')
                ->name('issue_vouchers.excess');

            Route::get('return-vouchers', [ReturnVoucherController::class, 'index'])
                ->name('return_vouchers.index');
            Route::get('return-vouchers/{return_voucher}', [ReturnVoucherController::class, 'show'])
                ->whereNumber('return_voucher')
                ->name('return_vouchers.show');

            Route::get('quality-sheets', [QualitySheetController::class, 'index'])
                ->name('quality_sheets.index');
            Route::get('quality-sheets/{quality_sheet}', [QualitySheetController::class, 'show'])
                ->whereNumber('quality_sheet')
                ->name('quality_sheets.show');

            Route::get('production-entries', [ProductionEntryController::class, 'index'])
                ->name('production_entries.index');
            Route::get('production-entries/{production_entry}', [ProductionEntryController::class, 'show'])
                ->whereNumber('production_entry')
                ->name('production_entries.show');
        });

        Route::middleware('throttle:api-reports')->group(function (): void {
            Route::get('work-orders/{work_order}/material-requirement', [WorkOrderController::class, 'materialRequirement'])
                ->whereNumber('work_order')
                ->name('work_orders.material_requirement');
            Route::get('work-orders/{work_order}/material-variance', [WorkOrderController::class, 'materialVariance'])
                ->whereNumber('work_order')
                ->name('work_orders.material_variance');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('work-orders', [WorkOrderController::class, 'store'])
                ->name('work_orders.store');
            Route::patch('work-orders/{work_order}', [WorkOrderController::class, 'update'])
                ->whereNumber('work_order')
                ->name('work_orders.update');
            Route::delete('work-orders/{work_order}', [WorkOrderController::class, 'destroy'])
                ->whereNumber('work_order')
                ->name('work_orders.destroy');

            Route::prefix('work-orders/{work_order}')->whereNumber('work_order')->group(function (): void {
                // PUT, not PATCH: the whole list is replaced in one atomic
                // request. See WorkOrderController::replaceMaterials.
                Route::put('outputs', [WorkOrderController::class, 'replaceOutputs'])
                    ->name('work_orders.outputs.replace');
                Route::put('materials', [WorkOrderController::class, 'replaceMaterials'])
                    ->name('work_orders.materials.replace');
                Route::post('fetch-standard-materials', [WorkOrderController::class, 'fetchStandardMaterials'])
                    ->name('work_orders.materials.fetch_standard');

                // The gate chain. Named after the act, not the target status,
                // so the client reads as the business does.
                Route::post('approve-order', [WorkOrderController::class, 'approveOrder'])
                    ->name('work_orders.approve_order');
                Route::post('start', [WorkOrderController::class, 'start'])
                    ->name('work_orders.start');
                Route::post('submit-qa', [WorkOrderController::class, 'submitQa'])
                    ->name('work_orders.submit_qa');
                Route::post('approve-qa', [WorkOrderController::class, 'approveQa'])
                    ->name('work_orders.approve_qa');
                Route::post('finish-manufacturing', [WorkOrderController::class, 'finishManufacturing'])
                    ->name('work_orders.finish_manufacturing');
                Route::post('complete', [WorkOrderController::class, 'complete'])
                    ->name('work_orders.complete');

                // Idempotent: returns the order's existing sheet rather than
                // opening a second one.
                Route::post('quality-sheet', [QualitySheetController::class, 'ensureForWorkOrder'])
                    ->name('work_orders.quality_sheet');
            });

            Route::post('issue-vouchers', [IssueVoucherController::class, 'store'])
                ->name('issue_vouchers.store');
            Route::put('issue-vouchers/{issue_voucher}/lines', [IssueVoucherController::class, 'replaceLines'])
                ->whereNumber('issue_voucher')
                ->name('issue_vouchers.lines.replace');
            Route::post('issue-vouchers/{issue_voucher}/post', [IssueVoucherController::class, 'post'])
                ->whereNumber('issue_voucher')
                ->name('issue_vouchers.post');
            Route::delete('issue-vouchers/{issue_voucher}', [IssueVoucherController::class, 'destroy'])
                ->whereNumber('issue_voucher')
                ->name('issue_vouchers.destroy');

            Route::post('return-vouchers', [ReturnVoucherController::class, 'store'])
                ->name('return_vouchers.store');
            Route::put('return-vouchers/{return_voucher}/lines', [ReturnVoucherController::class, 'replaceLines'])
                ->whereNumber('return_voucher')
                ->name('return_vouchers.lines.replace');
            Route::post('return-vouchers/{return_voucher}/post', [ReturnVoucherController::class, 'post'])
                ->whereNumber('return_voucher')
                ->name('return_vouchers.post');
            Route::delete('return-vouchers/{return_voucher}', [ReturnVoucherController::class, 'destroy'])
                ->whereNumber('return_voucher')
                ->name('return_vouchers.destroy');

            Route::put('quality-sheets/{quality_sheet}/lines', [QualitySheetController::class, 'replaceLines'])
                ->whereNumber('quality_sheet')
                ->name('quality_sheets.lines.replace');
            Route::post('quality-sheets/{quality_sheet}/fill', [QualitySheetController::class, 'fill'])
                ->whereNumber('quality_sheet')
                ->name('quality_sheets.fill');
            Route::post('quality-sheets/{quality_sheet}/approve', [QualitySheetController::class, 'approve'])
                ->whereNumber('quality_sheet')
                ->name('quality_sheets.approve');
            Route::delete('quality-sheets/{quality_sheet}', [QualitySheetController::class, 'destroy'])
                ->whereNumber('quality_sheet')
                ->name('quality_sheets.destroy');
        });
    });

    /*
    | Module 8 - Delivery & Field Ops
    |
    | What happens after the product is made: handing it to the customer,
    | writing the minute, going to site.
    |
    | The delivery voucher's DUAL SIGNATURE is the shape to notice. Technical
    | and financial approval are two endpoints with two permissions, and the
    | second one to arrive activates the voucher - deducting finished goods,
    | debiting the customer, and closing the operation's cost centre. One
    | person holding both permissions would defeat the point of having two, so
    | they are never merged into one "approve" route.
    |
    | Everything else here is a plain document with a small state machine:
    | distribute for a minute, start/complete for an installation.
    */
    Route::middleware('ability:delivery')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('delivery-vouchers', [DeliveryVoucherController::class, 'index'])
                ->name('delivery_vouchers.index');
            Route::get('delivery-vouchers/{delivery_voucher}', [DeliveryVoucherController::class, 'show'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.show');

            Route::get('delivery-minutes', [DeliveryMinuteController::class, 'index'])
                ->name('delivery_minutes.index');
            Route::get('delivery-minutes/{delivery_minute}', [DeliveryMinuteController::class, 'show'])
                ->whereNumber('delivery_minute')
                ->name('delivery_minutes.show');

            Route::get('installations', [InstallationController::class, 'index'])
                ->name('installations.index');
            Route::get('installations/{installation}', [InstallationController::class, 'show'])
                ->whereNumber('installation')
                ->name('installations.show');

            Route::get('site-surveys', [SiteSurveyController::class, 'index'])
                ->name('site_surveys.index');
            Route::get('site-surveys/{site_survey}', [SiteSurveyController::class, 'show'])
                ->whereNumber('site_survey')
                ->name('site_surveys.show');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('delivery-vouchers', [DeliveryVoucherController::class, 'store'])
                ->name('delivery_vouchers.store');
            Route::patch('delivery-vouchers/{delivery_voucher}', [DeliveryVoucherController::class, 'update'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.update');
            Route::put('delivery-vouchers/{delivery_voucher}/lines', [DeliveryVoucherController::class, 'replaceLines'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.lines.replace');
            Route::delete('delivery-vouchers/{delivery_voucher}', [DeliveryVoucherController::class, 'destroy'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.destroy');

            // Two signatures, two permissions, either order. The second one
            // activates the voucher.
            Route::post('delivery-vouchers/{delivery_voucher}/approve-technical', [DeliveryVoucherController::class, 'approveTechnical'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.approve_technical');
            Route::post('delivery-vouchers/{delivery_voucher}/approve-financial', [DeliveryVoucherController::class, 'approveFinancial'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.approve_financial');
            Route::post('delivery-vouchers/{delivery_voucher}/cancel', [DeliveryVoucherController::class, 'cancel'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.cancel');

            Route::post('delivery-minutes', [DeliveryMinuteController::class, 'store'])
                ->name('delivery_minutes.store');
            Route::patch('delivery-minutes/{delivery_minute}', [DeliveryMinuteController::class, 'update'])
                ->whereNumber('delivery_minute')
                ->name('delivery_minutes.update');
            Route::post('delivery-minutes/{delivery_minute}/distribute', [DeliveryMinuteController::class, 'distribute'])
                ->whereNumber('delivery_minute')
                ->name('delivery_minutes.distribute');
            Route::delete('delivery-minutes/{delivery_minute}', [DeliveryMinuteController::class, 'destroy'])
                ->whereNumber('delivery_minute')
                ->name('delivery_minutes.destroy');

            Route::post('installations', [InstallationController::class, 'store'])
                ->name('installations.store');
            Route::patch('installations/{installation}', [InstallationController::class, 'update'])
                ->whereNumber('installation')
                ->name('installations.update');
            Route::post('installations/{installation}/start', [InstallationController::class, 'start'])
                ->whereNumber('installation')
                ->name('installations.start');
            Route::post('installations/{installation}/complete', [InstallationController::class, 'complete'])
                ->whereNumber('installation')
                ->name('installations.complete');
            Route::delete('installations/{installation}', [InstallationController::class, 'destroy'])
                ->whereNumber('installation')
                ->name('installations.destroy');

            Route::post('site-surveys', [SiteSurveyController::class, 'store'])
                ->name('site_surveys.store');
            Route::patch('site-surveys/{site_survey}', [SiteSurveyController::class, 'update'])
                ->whereNumber('site_survey')
                ->name('site_surveys.update');
            Route::delete('site-surveys/{site_survey}', [SiteSurveyController::class, 'destroy'])
                ->whereNumber('site_survey')
                ->name('site_surveys.destroy');
        });
    });

    /*
    | Module 9 - Finance & Accounting
    |
    | The chart of accounts, the journal, and every document that puts money on
    | a customer's or a supplier's account.
    |
    | One rule runs through all of it: a POSTED document is immutable. A posted
    | journal entry has no edit and no delete route at all, because its numbers
    | have already reached the trial balance and every statement built on it;
    | the correction is a reversing entry. The same logic makes a payment
    | freeze once its journal entry exists, and makes cost-centre closings
    | reversible rather than deletable.
    |
    | Party statements are read-only for the same reason from the other end:
    | those rows are written by documents, and an editable statement could be
    | brought into line with a balance somebody expected rather than with the
    | documents that produced it.
    |
    | The two statement endpoints sit on the reports limiter - each walks a
    | party's whole history to build a running balance.
    */
    Route::middleware('ability:finance')->group(function (): void {

        Route::middleware('throttle:api-read')->group(function (): void {
            Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::get('accounts/{account}', [AccountController::class, 'show'])
                ->whereNumber('account')
                ->name('accounts.show');

            Route::get('journal-entries', [JournalEntryController::class, 'index'])
                ->name('journal_entries.index');
            Route::get('journal-entries/{journal_entry}', [JournalEntryController::class, 'show'])
                ->whereNumber('journal_entry')
                ->name('journal_entries.show');

            Route::get('sales-invoices', [SalesInvoiceController::class, 'index'])
                ->name('sales_invoices.index');
            Route::get('sales-invoices/{sales_invoice}', [SalesInvoiceController::class, 'show'])
                ->whereNumber('sales_invoice')
                ->name('sales_invoices.show');

            Route::get('operation-payments', [OperationPaymentController::class, 'index'])
                ->name('operation_payments.index');
            Route::get('operation-payments/{operation_payment}', [OperationPaymentController::class, 'show'])
                ->whereNumber('operation_payment')
                ->name('operation_payments.show');
            Route::get('projects/{project}/payment-totals', [OperationPaymentController::class, 'totalsForProject'])
                ->whereNumber('project')
                ->name('projects.payment_totals');

            Route::get('financial-claims', [FinancialClaimController::class, 'index'])
                ->name('financial_claims.index');
            Route::get('financial-claims/{financial_claim}', [FinancialClaimController::class, 'show'])
                ->whereNumber('financial_claim')
                ->name('financial_claims.show');

            Route::get('credit-facilities', [CreditFacilityController::class, 'index'])
                ->name('credit_facilities.index');
            Route::get('credit-facilities/{credit_facility}', [CreditFacilityController::class, 'show'])
                ->whereNumber('credit_facility')
                ->name('credit_facilities.show');

            Route::get('account-entries', [AccountEntryController::class, 'index'])
                ->name('account_entries.index');

            Route::get('cost-center-closings', [CostCenterClosingController::class, 'index'])
                ->name('cost_center_closings.index');
            Route::get('projects/{project}/cost-center', [CostCenterClosingController::class, 'status'])
                ->whereNumber('project')
                ->name('projects.cost_center.status');
        });

        Route::middleware('throttle:api-reports')->group(function (): void {
            Route::get('customers/{customer}/statement', [AccountEntryController::class, 'customerStatement'])
                ->whereNumber('customer')
                ->name('customers.statement');
            Route::get('suppliers/{supplier}/statement', [AccountEntryController::class, 'supplierStatement'])
                ->whereNumber('supplier')
                ->name('suppliers.statement');
        });

        Route::middleware('throttle:api-write')->group(function (): void {
            Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
            Route::patch('accounts/{account}', [AccountController::class, 'update'])
                ->whereNumber('account')
                ->name('accounts.update');
            Route::delete('accounts/{account}', [AccountController::class, 'destroy'])
                ->whereNumber('account')
                ->name('accounts.destroy');

            // No update or delete route for a POSTED entry exists anywhere:
            // the two below are gated on isDraft() by JournalEntryPolicy.
            Route::post('journal-entries', [JournalEntryController::class, 'store'])
                ->name('journal_entries.store');
            Route::patch('journal-entries/{journal_entry}', [JournalEntryController::class, 'update'])
                ->whereNumber('journal_entry')
                ->name('journal_entries.update');
            Route::put('journal-entries/{journal_entry}/lines', [JournalEntryController::class, 'replaceLines'])
                ->whereNumber('journal_entry')
                ->name('journal_entries.lines.replace');
            Route::post('journal-entries/{journal_entry}/post', [JournalEntryController::class, 'post'])
                ->whereNumber('journal_entry')
                ->name('journal_entries.post');
            Route::delete('journal-entries/{journal_entry}', [JournalEntryController::class, 'destroy'])
                ->whereNumber('journal_entry')
                ->name('journal_entries.destroy');

            // An invoice is raised against the delivery it invoices, so the
            // voucher is in the path rather than in the body - there is no way
            // to write one that names a different customer from the delivery.
            Route::post('delivery-vouchers/{delivery_voucher}/invoices', [SalesInvoiceController::class, 'store'])
                ->whereNumber('delivery_voucher')
                ->name('delivery_vouchers.invoices.store');
            Route::delete('sales-invoices/{sales_invoice}', [SalesInvoiceController::class, 'destroy'])
                ->whereNumber('sales_invoice')
                ->name('sales_invoices.destroy');

            Route::post('operation-payments', [OperationPaymentController::class, 'store'])
                ->name('operation_payments.store');
            Route::post('operation-payments/{operation_payment}/allocate/{financial_claim}', [OperationPaymentController::class, 'allocate'])
                ->whereNumber('operation_payment')
                ->whereNumber('financial_claim')
                ->name('operation_payments.allocate');
            Route::delete('operation-payments/{operation_payment}', [OperationPaymentController::class, 'destroy'])
                ->whereNumber('operation_payment')
                ->name('operation_payments.destroy');

            Route::post('financial-claims', [FinancialClaimController::class, 'store'])
                ->name('financial_claims.store');
            Route::patch('financial-claims/{financial_claim}', [FinancialClaimController::class, 'update'])
                ->whereNumber('financial_claim')
                ->name('financial_claims.update');
            Route::post('financial-claims/{financial_claim}/submit', [FinancialClaimController::class, 'submit'])
                ->whereNumber('financial_claim')
                ->name('financial_claims.submit');
            Route::post('financial-claims/{financial_claim}/collect', [FinancialClaimController::class, 'collect'])
                ->whereNumber('financial_claim')
                ->name('financial_claims.collect');
            Route::delete('financial-claims/{financial_claim}', [FinancialClaimController::class, 'destroy'])
                ->whereNumber('financial_claim')
                ->name('financial_claims.destroy');

            Route::post('credit-facilities', [CreditFacilityController::class, 'store'])
                ->name('credit_facilities.store');
            Route::patch('credit-facilities/{credit_facility}', [CreditFacilityController::class, 'update'])
                ->whereNumber('credit_facility')
                ->name('credit_facilities.update');
            Route::post('credit-facilities/{credit_facility}/allocate', [CreditFacilityController::class, 'allocate'])
                ->whereNumber('credit_facility')
                ->name('credit_facilities.allocate');
            Route::post('facility-allocations/{facility_allocation}/release', [CreditFacilityController::class, 'release'])
                ->whereNumber('facility_allocation')
                ->name('facility_allocations.release');
            Route::delete('credit-facilities/{credit_facility}', [CreditFacilityController::class, 'destroy'])
                ->whereNumber('credit_facility')
                ->name('credit_facilities.destroy');

            Route::post('projects/{project}/close-cost-center', [CostCenterClosingController::class, 'close'])
                ->whereNumber('project')
                ->name('projects.cost_center.close');

            // A reversal, never a deletion - the journal entry behind a closing
            // is posted and immutable.
            Route::post('cost-center-closings/{cost_center_closing}/reverse', [CostCenterClosingController::class, 'reverse'])
                ->whereNumber('cost_center_closing')
                ->name('cost_center_closings.reverse');
        });
    });

    /*
    | Module 10 - Reports & Documents
    |
    | Everything here walks the ledger, so the WHOLE module sits on the reports
    | limiter - much tighter than the read one. These are meant to be fetched
    | on demand and cached by the client, never polled.
    |
    | Each report keeps the panel's own permission (trial_balance.view,
    | general_ledger.view, ...) rather than a single "reports" one: seeing what
    | an operation cost and seeing the company's balance sheet are different
    | privileges.
    |
    | The document routes stream application/pdf rather than the JSON envelope,
    | and delegate to the SAME controllers the admin panel prints through, so
    | the paper coming out of a phone is the paper coming out of a desktop.
    */
    Route::middleware(['ability:reports', 'throttle:api-reports'])->group(function (): void {

        Route::prefix('reports')->group(function (): void {
            Route::get('trial-balance', [ReportController::class, 'trialBalance'])
                ->name('reports.trial_balance');
            Route::get('general-ledger', [ReportController::class, 'generalLedger'])
                ->name('reports.general_ledger');
            Route::get('journal-daybook', [ReportController::class, 'journalDaybook'])
                ->name('reports.journal_daybook');
            Route::get('income-statement', [ReportController::class, 'incomeStatement'])
                ->name('reports.income_statement');
            Route::get('balance-sheet', [ReportController::class, 'balanceSheet'])
                ->name('reports.balance_sheet');
            Route::get('cash-flow', [ReportController::class, 'cashFlow'])
                ->name('reports.cash_flow');
            Route::get('operating-statement', [ReportController::class, 'operatingStatement'])
                ->name('reports.operating_statement');
        });

        Route::get('projects/{project}/cost-breakdown', [ReportController::class, 'operationCost'])
            ->whereNumber('project')
            ->name('projects.cost_breakdown');
        Route::get('projects/{project}/timeline', [ReportController::class, 'operationTimeline'])
            ->whereNumber('project')
            ->name('projects.timeline');

        // PDFs. Each keeps its own print permission, enforced inside the
        // shared controller - being able to read a record is not being able to
        // print it.
        Route::prefix('documents')->group(function (): void {
            Route::get('offers/{offer}', [DocumentController::class, 'offer'])
                ->whereNumber('offer')
                ->name('documents.offer');
            Route::get('purchase-orders/{purchase_order}', [DocumentController::class, 'purchaseOrder'])
                ->whereNumber('purchase_order')
                ->name('documents.purchase_order');
            Route::get('quality-sheets/{quality_sheet}', [DocumentController::class, 'qualitySheet'])
                ->whereNumber('quality_sheet')
                ->name('documents.quality_sheet');
            Route::get('work-orders/{work_order}/material-variance', [DocumentController::class, 'workOrderMaterialVariance'])
                ->whereNumber('work_order')
                ->name('documents.work_order_material_variance');
            Route::get('journal-daybook', [DocumentController::class, 'journalDaybook'])
                ->name('documents.journal_daybook');
            Route::get('general-ledger', [DocumentController::class, 'generalLedger'])
                ->name('documents.general_ledger');
            Route::get('financial-statements', [DocumentController::class, 'financialStatements'])
                ->name('documents.financial_statements');
        });
    });

    /*
    | Module 11 - Cross-cutting
    |
    | The dashboard, notifications, the activity log and global search.
    |
    | Deliberately NOT behind a token ability. Every other module has a natural
    | scope a device token can be narrowed to; these span all of them, so
    | gating them on any one ability would be arbitrary, and inventing a
    | "platform" ability would lock every EXISTING token out of the bell and
    | the search box on the first deploy - tokens carry the abilities they were
    | issued with, and there is no way to widen one that is already out there.
    |
    | Authorization is per endpoint instead: the dashboard on dashboard.view,
    | the activity log on activity_log.view, search on each type's own view
    | permission, and notifications on nothing at all - they are self-scoped,
    | which is the strongest rule available. There is no endpoint here that
    | reads another user's notifications.
    */
    Route::middleware('throttle:api-read')->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
            ->name('notifications.unread_count');

        Route::get('activity-log', [ActivityLogController::class, 'index'])
            ->name('activity_log.index');

        Route::get('search', [SearchController::class, 'index'])->name('search');
    });

    // The dashboard aggregates availability per item across every warehouse,
    // which is the dearest query in the platform. It shares the panel's cache
    // keys, so a phone and a desktop show the same figures.
    Route::middleware('throttle:api-reports')->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    });

    Route::middleware('throttle:api-write')->group(function (): void {
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.read_all');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])
            ->name('notifications.destroy');
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
