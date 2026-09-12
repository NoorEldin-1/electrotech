<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\FinancialStatementPdfController;
use App\Http\Controllers\GeneralLedgerPdfController;
use App\Http\Controllers\JournalDaybookPdfController;
use App\Http\Controllers\OfferPdfController;
use App\Http\Controllers\PurchaseOrderPdfController;
use App\Http\Controllers\QualitySheetPdfController;
use App\Http\Controllers\WorkOrderMaterialVariancePdfController;
use App\Models\ProjectOffer;
use App\Models\PurchaseOrder;
use App\Models\QualitySheet;
use App\Models\WorkOrder;
use App\Services\GeneralLedgerService;
use App\Services\JournalDaybookService;
use App\Services\WorkOrderMaterialVarianceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group 38. Printable documents
 *
 * The printable PDFs — an offer, a purchase order, a quality certificate, the
 * daybook, the ledger, the financial statements, a work order's material
 * variance.
 *
 * Every endpoint here **streams `application/pdf`, not the JSON envelope.**
 * That is the one place in the API where the response is not an envelope, and
 * it is deliberate: a client that wants a document wants the bytes to hand to
 * the platform's viewer or share sheet, not a base64 blob inside JSON that it
 * would have to decode and write to a file first.
 *
 * These delegate to the **same controllers the admin panel prints through**,
 * so the paper coming out of a phone is byte-for-byte the paper coming out of
 * a desktop. Re-implementing the layouts here would guarantee the two drift,
 * and the printed offer a customer holds is not a thing to let drift.
 *
 * Each document keeps its own print permission (`project_offers.print`,
 * `purchase_orders.print`, `quality_sheets.print`, and the report permissions
 * for the finance documents), enforced inside the shared controller. Being
 * able to read a record through the API is not the same as being able to print
 * it.
 *
 * **Language**: pass `?lang=ar` or `?lang=en` to choose the document's
 * language independently of the caller's UI locale — the customer's copy of an
 * offer is not always in the language of whoever pressed print. An unknown or
 * missing value falls back to each document's own default.
 *
 * They sit on the reports rate limiter: rendering a PDF is the most expensive
 * thing the API does.
 */
class DocumentController extends ApiController
{
    /**
     * Offer PDF
     *
     * عرض السعر — the printable quotation, watermarked, in Arabic or English.
     * Defaults to English, which is what offers have defaulted to since 2026.
     *
     * @authenticated
     *
     * @urlParam offer integer required The offer id. Example: 25
     *
     * @queryParam lang string ar or en. Example: ar
     *
     * @response 200 scenario="Success" "<binary PDF>"
     * @response 403 scenario="No print permission" {"error":{"code":"forbidden","message":"You do not have permission to perform this action."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function offer(ProjectOffer $offer): Response
    {
        return app(OfferPdfController::class)->show($offer);
    }

    /**
     * Purchase order PDF
     *
     * أمر التوريد — the order as sent to the supplier.
     *
     * @authenticated
     *
     * @urlParam purchase_order integer required The order id. Example: 14
     *
     * @queryParam lang string ar or en. Example: ar
     *
     * @response 200 scenario="Success" "<binary PDF>"
     */
    public function purchaseOrder(PurchaseOrder $purchaseOrder): Response
    {
        return app(PurchaseOrderPdfController::class)->show($purchaseOrder);
    }

    /**
     * Quality certificate PDF
     *
     * ورقة الجودة — the inspection form with its test grid and both
     * signatures, laid out as the paper version.
     *
     * @authenticated
     *
     * @urlParam quality_sheet integer required The sheet id. Example: 12
     *
     * @queryParam lang string ar or en. Example: ar
     *
     * @response 200 scenario="Success" "<binary PDF>"
     */
    public function qualitySheet(QualitySheet $qualitySheet): Response
    {
        return app(QualitySheetPdfController::class)->show($qualitySheet);
    }

    /**
     * Work-order material variance PDF
     *
     * فروق خامات أمر التصنيع — planned against issued, per material, in
     * quantity and in money.
     *
     * @authenticated
     *
     * @urlParam work_order integer required The order id. Example: 31
     *
     * @response 200 scenario="Success" "<binary PDF>"
     */
    public function workOrderMaterialVariance(WorkOrder $workOrder): Response
    {
        return app(WorkOrderMaterialVariancePdfController::class)(
            $workOrder,
            app(WorkOrderMaterialVarianceService::class),
        );
    }

    /**
     * Journal daybook PDF
     *
     * دفتر اليومية التحليلي — the analytical daybook for a period, entries as
     * rows against accounts as columns. Takes the same query parameters as the
     * JSON endpoint.
     *
     * @authenticated
     *
     * @queryParam from date Inclusive. Example: 2026-01-01
     * @queryParam to date Inclusive. Example: 2026-01-31
     * @queryParam account_ids string Comma-separated account ids to pin the columns to. Example: 12,31
     *
     * @response 200 scenario="Success" "<binary PDF>"
     */
    public function journalDaybook(Request $request): Response
    {
        return app(JournalDaybookPdfController::class)($request, app(JournalDaybookService::class));
    }

    /**
     * General ledger PDF
     *
     * دفتر الأستاذ — one account's movements for a period, with its opening
     * and closing balances.
     *
     * @authenticated
     *
     * @queryParam account_id integer required The account to print. Example: 12
     * @queryParam from date Inclusive. Example: 2026-01-01
     * @queryParam to date Inclusive. Example: 2026-06-30
     *
     * @response 200 scenario="Success" "<binary PDF>"
     */
    public function generalLedger(Request $request): Response
    {
        return app(GeneralLedgerPdfController::class)($request, app(GeneralLedgerService::class));
    }

    /**
     * Financial statements PDF
     *
     * القوائم المالية — the statements that follow the trial balance, printed
     * as one document.
     *
     * @authenticated
     *
     * @queryParam from date Inclusive. Example: 2026-01-01
     * @queryParam to date Inclusive. Example: 2026-12-31
     *
     * @response 200 scenario="Success" "<binary PDF>"
     */
    public function financialStatements(Request $request): Response
    {
        return app(FinancialStatementPdfController::class)($request);
    }
}
