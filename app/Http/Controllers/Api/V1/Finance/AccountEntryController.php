<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Finance\AccountEntryResource;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\AccountStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 33. Party statements
 *
 * كشف حساب — what a customer or a supplier owes, and why.
 *
 * Entirely **read-only**, and not by omission: `AccountEntryPolicy` refuses
 * create, update and delete, and there are no write routes to match. These
 * rows are written by documents — posting an addition voucher credits its
 * supplier, activating a delivery voucher debits its customer. An editable
 * statement could be brought into line with a balance somebody expected,
 * rather than with the documents that produced it.
 *
 * The two shapes here answer different questions. The **statement** endpoints
 * give one party's movements in order with a running balance, which is the
 * document you print and send. The flat **index** is for searching across
 * parties — it carries no running balance, because on a filtered or re-sorted
 * list that number would change depending on how you looked at it.
 */
class AccountEntryController extends ApiController
{
    public function __construct(
        private readonly AccountStatementService $statements,
    ) {}

    /**
     * Search account movements
     *
     * Across every party. No running balance — see the group note.
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the operation name or notes. Example: 2026-14
     * @queryParam filter[party_type] string The party's class name. Example: App\Models\Customer
     * @queryParam filter[party_id] integer The party's id. Example: 7
     * @queryParam filter[direction] string debit or credit. Example: debit
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[entry_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: entry_date, amount, created_at. Example: -entry_date
     *
     * @response 200 scenario="Success" {"data":[{"id":88,"type":"account_entry","party_id":7,"direction":{"value":"debit","label":"Debit","color":"danger"},"amount":"250000.00","entry_date":"2026-09-12"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AccountEntry::class);

        $entries = ApiQuery::for(AccountEntry::query(), $request)
            ->allowFilters([
                'party_type' => ApiQuery::exact('party_type'),
                'party_id' => ApiQuery::exact('party_id'),
                'direction' => ApiQuery::exact('direction'),
                'project' => ApiQuery::exact('project_id'),
                'entry_date' => ApiQuery::dateBetween('entry_date'),
            ])
            ->allowSearch(['operation_name', 'notes'])
            ->allowSorts(['entry_date', 'amount', 'created_at'])
            ->defaultSort('-entry_date')
            ->paginate();

        return $this->respondPaginated(AccountEntryResource::collection($entries));
    }

    /**
     * A customer's statement
     *
     * Every movement in order, each carrying the running balance after it, plus
     * the closing balance. This is the document you print and send.
     *
     * It walks the customer's whole history, so it sits on the reports rate
     * limiter.
     *
     * @authenticated
     *
     * @urlParam customer integer required The customer id. Example: 7
     *
     * @response 200 scenario="Success" {"data":{"party_type":"customer","party_id":7,"balance":"100000.00","entries":[{"id":88,"direction":{"value":"debit","label":"Debit","color":"danger"},"amount":"250000.00","running_balance":"250000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function customerStatement(Customer $customer): JsonResponse
    {
        $this->authorizePermission('customer_statements.view');

        return $this->statement('customer', $customer);
    }

    /**
     * A supplier's statement
     *
     * The same shape as a customer's, and gated by its own permission —
     * seeing what the company owes its suppliers and what its customers owe it
     * are different privileges.
     *
     * @authenticated
     *
     * @urlParam supplier integer required The supplier id. Example: 3
     *
     * @response 200 scenario="Success" {"data":{"party_type":"supplier","party_id":3,"balance":"-40000.00","entries":[{"id":91,"direction":{"value":"credit","label":"Credit","color":"success"},"amount":"40000.00","running_balance":"-40000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function supplierStatement(Supplier $supplier): JsonResponse
    {
        $this->authorizePermission('supplier_statements.view');

        return $this->statement('supplier', $supplier);
    }

    private function statement(string $partyType, \Illuminate\Database\Eloquent\Model $party): JsonResponse
    {
        $entries = $this->statements->for($party);

        return $this->respond([
            'party_type' => $partyType,
            'party_id' => $party->getKey(),
            'balance' => number_format($this->statements->balanceFor($party), 2, '.', ''),
            'entries' => AccountEntryResource::collection($entries)->resolve(),
        ]);
    }
}
