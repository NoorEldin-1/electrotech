<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Finance\StoreAccountRequest;
use App\Http\Requests\Api\V1\Finance\UpdateAccountRequest;
use App\Http\Resources\Api\V1\Finance\AccountResource;
use App\Enums\AccountType;
use App\Models\Account;
use App\Services\GeneralLedgerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group 29. Chart of accounts
 *
 * دليل الحسابات — the accounts every journal line points at.
 *
 * Two fields on an account decide how the rest of the platform reads it.
 * `type` fixes its natural side, published as `natural_sign`: multiply that by
 * (debit − credit) and you get a balance the right way up, which is what stops
 * a client showing every liability as a negative number.
 * `statement_section` is the axis the financial statements are built on — an
 * account without one still posts and still appears in the trial balance, but
 * lands nowhere on the income statement or the balance sheet.
 *
 * The detail endpoint carries the account's **balance**; the list does not.
 * Working a balance out means walking the account's posted lines, so a page of
 * two hundred accounts would pay for two hundred walks.
 */
class AccountController extends ApiController
{
    public function __construct(
        private readonly GeneralLedgerService $ledger,
    ) {}

    /**
     * List accounts
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 50
     * @queryParam search string Matches the code or either name. Example: 1300
     * @queryParam filter[type] string asset, liability, equity, revenue or expense. Example: expense
     * @queryParam filter[statement_section] string One or more statement_section values. Example: current_assets
     * @queryParam filter[is_active] boolean Example: true
     * @queryParam filter[parent] integer Direct children of this account. Example: 12
     * @queryParam filter[currency] string Example: EGP
     * @queryParam sort string Allowed: code, name, type, created_at. Example: code
     * @queryParam include string Allowed: parent. Example: parent
     *
     * @response 200 scenario="Success" {"data":[{"id":12,"type":"account","code":"1300","name":"المخزون","account_type":{"value":"asset","label":"Asset","color":"info"},"natural_sign":1,"is_active":true}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":50,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $accounts = ApiQuery::for(Account::query()->withCount('children'), $request)
            ->allowFilters([
                'type' => ApiQuery::exact('type'),
                'statement_section' => ApiQuery::exact('statement_section'),
                'is_active' => ApiQuery::boolean('is_active'),
                'parent' => ApiQuery::exact('parent_id'),
                'currency' => ApiQuery::exact('currency'),
            ])
            ->allowSearch(['code', 'name', 'name_en'])
            ->allowSorts(['code', 'name', 'type', 'created_at'])
            ->allowIncludes(['parent'])
            ->defaultSort('code')
            ->paginate();

        return $this->respondPaginated(AccountResource::collection($accounts));
    }

    /**
     * Show an account
     *
     * Carries the account's closing balance, which the list deliberately does
     * not — see the group note.
     *
     * @authenticated
     *
     * @urlParam account integer required The account id. Example: 12
     *
     * @response 200 scenario="Success" {"data":{"id":12,"type":"account","code":"1300","name":"المخزون","natural_sign":1,"balance":"482300.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        return $this->respond(
            (new AccountResource($account->loadCount(['children', 'journalLines'])->load('parent')))
                ->additional([
                    'with_balance' => true,
                    'balance' => $this->ledger->closingBalance($account),
                ]),
        );
    }

    /**
     * Add an account
     *
     * @authenticated
     *
     * @bodyParam code string required Unique; every report orders by it. Example: 5070
     * @bodyParam name string required Example: تكلفة المبيعات
     * @bodyParam name_en string optional Example: Cost of sales
     * @bodyParam type string required asset, liability, equity, revenue or expense. Example: expense
     * @bodyParam nature string optional debit or credit; defaults to the type's natural side. Example: debit
     * @bodyParam statement_section string optional Where the account lands on the financial statements. Example: cost_of_sales
     * @bodyParam currency string optional Three letters. Example: EGP
     * @bodyParam parent_id integer optional Example: 5000
     * @bodyParam opening_balance number optional What the account carried when the books were taken over. Example: 0
     * @bodyParam opening_balance_date date optional Example: 2026-01-01
     * @bodyParam is_active boolean optional Defaults to true. Example: true
     *
     * @response 201 scenario="Created" {"data":{"id":41,"type":"account","code":"5070","name":"تكلفة المبيعات"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $data = $request->validated();

        // `nature` is NOT NULL, and for all but a contra account it is simply
        // the type's own side. Defaulting it here keeps the common call to
        // three fields instead of four, and keeps a caller from having to know
        // that an expense is a debit account.
        $data['nature'] ??= AccountType::from($data['type'])->naturalDirection();

        $account = Account::create(array_merge(
            ['is_active' => true, 'currency' => 'EGP'],
            $data,
        ));

        return $this->respondCreated(new AccountResource($account));
    }

    /**
     * Update an account
     *
     * @authenticated
     *
     * @urlParam account integer required The account id. Example: 41
     *
     * @bodyParam name string optional Example: تكلفة المبيعات والخدمات
     * @bodyParam statement_section string optional Example: cost_of_sales
     * @bodyParam is_active boolean optional Example: false
     *
     * @response 200 scenario="Updated" {"data":{"id":41,"type":"account","is_active":false},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $account->update($request->validated());

        return $this->respond(new AccountResource($account->fresh()));
    }

    /**
     * Delete an account
     *
     * Refused when the account already carries journal lines, or when it has
     * sub-accounts. Both refusals protect something that cannot be rebuilt: a
     * deleted account would leave posted lines pointing at nothing, and a
     * deleted parent would orphan a whole branch of the chart.
     *
     * Deactivate it instead — `is_active: false` keeps the history readable
     * while stopping anyone posting to it again.
     *
     * @authenticated
     *
     * @urlParam account integer required The account id. Example: 41
     *
     * @response 204 scenario="Deleted" {}
     * @response 422 scenario="Has journal lines" {"error":{"code":"business_rule_violated","message":"Cannot delete an account that already carries journal lines."},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        if ($account->journalLines()->exists()) {
            throw new DomainException(__('errors.api.account_has_entries'));
        }

        if ($account->children()->exists()) {
            throw new DomainException(__('errors.api.account_has_children'));
        }

        $account->delete();

        return $this->respondNoContent();
    }
}
