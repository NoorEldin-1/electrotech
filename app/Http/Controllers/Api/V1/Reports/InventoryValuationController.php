<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Api\ReportSerializer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\InventoryValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @group 37. Reports
 */
class InventoryValuationController extends ApiController
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
    ) {}

    /**
     * Inventory valuation
     *
     * تقييم المخزون — the value of the stock at a date, read two ways and set
     * side by side: **`ledger`** is the posted balance of the three inventory
     * accounts (1310 raw materials, 1320 work in progress, 1330 finished goods)
     * and **`stock_card`** is the moving weighted-average value of the items
     * in the matching warehouses. `difference` is `ledger - stock_card`.
     *
     * The two agree only for stock that moved after the automatic inventory
     * journals went live. History before that was never journalled to the
     * three accounts, so `legacy` carries the balance still sitting on the old
     * inventory account (1300) until Finance reclassifies it with one manual
     * entry, and `totals.ledger` includes it. A non-zero `difference` is a
     * finding to read, not a rounding artefact — `reconciled` is false when it
     * exceeds one cent.
     *
     * `wip_operations` breaks work in progress down per operation: the ledger
     * side from journal lines tagged to the operation, the stock side from the
     * WIP warehouse movements of that operation. A row with `project: null`
     * holds whatever cannot be tied to an operation. A store whose account is
     * missing from the chart has `ledger` and `difference` null and its code
     * listed in `missing_account_codes`.
     *
     * Requires `reports.inventory_valuation`.
     *
     * @authenticated
     *
     * @queryParam as_of date Position as at this date, inclusive. Defaults to today's position. Example: 2026-12-31
     *
     * @response 200 scenario="Success" {"data":{"as_of":"2026-12-31","stores":[{"key":"raw_materials","warehouse":{"value":"raw_materials","label":"Raw Materials","color":"info"},"account":{"id":13,"code":"1310","name":"مخزن الخامات"},"ledger":"430000.00","stock_card":"430000.00","difference":"0.00","reconciled":true}],"legacy":{"account":{"id":12,"code":"1300","name":"المخزون"},"balance":"0.00"},"totals":{"ledger":"430000.00","stock_card":"430000.00","difference":"0.00","reconciled":true},"wip_operations":[{"project":{"id":4,"code":"2026-4","name":"Substation A"},"ledger":"120000.00","stock_card":"120000.00","difference":"0.00"}],"missing_account_codes":[]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.inventory_valuation');

        $asOf = $this->asOf($request);

        return $this->respond(ReportSerializer::normalize(
            $this->valuation->build($asOf),
        ));
    }

    private function asOf(Request $request): ?Carbon
    {
        $raw = trim((string) $request->query('as_of', ''));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'as_of' => ['as_of must be a valid date.'],
            ]);
        }
    }
}
