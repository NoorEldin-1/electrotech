<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Api\ReportSerializer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Account;
use App\Models\Project;
use App\Services\BalanceSheetService;
use App\Services\CashFlowStatementService;
use App\Services\GeneralLedgerService;
use App\Services\IncomeStatementService;
use App\Services\JournalDaybookService;
use App\Services\OperatingStatementService;
use App\Services\OperationCostService;
use App\Services\OperationTimelineService;
use App\Services\TrialBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
// Laravel's Carbon subclass, not the base one: every report service
// type-hints `Illuminate\Support\Carbon`, and a base Carbon\Carbon is not an
// instance of it.
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @group 37. Reports
 *
 * The financial reports, and the two views of an operation that summarize
 * everything else: what it cost, and how far along it is.
 *
 * Three things are true of every endpoint here and are not repeated below.
 *
 * **They are on the reports rate limiter**, which is much tighter than the
 * read limiter. Each one walks the ledger — the trial balance walks every
 * active account's whole posted history — so they are meant to be fetched on
 * demand and cached by the client, never polled.
 *
 * **Every money figure is a decimal string**, at two places. A JSON number is
 * a binary double in Dart, and a statement re-summed on the client would drift
 * from the one the ledger holds (API_Development_Plan.md §3.10). Accounts
 * inside a report appear as `{id, code, name, type}`, never as full records.
 *
 * **Each carries its own permission**, matching the panel's: `trial_balance.view`,
 * `general_ledger.view`, `journal_daybook.view`, `income_statement.view`,
 * `balance_sheet.view`, `cash_flow_statement.view`, `operating_statement.view`.
 * Seeing what an operation cost and seeing the company's balance sheet are
 * different privileges and stay that way.
 *
 * Dates are inclusive on both ends. An end before its start is a 422 rather
 * than an empty report, because an empty report looks like a true answer.
 */
class ReportController extends ApiController
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly GeneralLedgerService $ledger,
        private readonly JournalDaybookService $daybook,
        private readonly IncomeStatementService $income,
        private readonly BalanceSheetService $balanceSheet,
        private readonly CashFlowStatementService $cashFlow,
        private readonly OperatingStatementService $operating,
        private readonly OperationCostService $operationCost,
        private readonly OperationTimelineService $timeline,
    ) {}

    /**
     * Trial balance
     *
     * ميزان المراجعة — every active account with movement or a balance,
     * grouped **by currency**, each group carrying its own totals and a
     * `balanced` flag.
     *
     * The grouping is not cosmetic: totalling accounts in different currencies
     * into one column would produce a number that balances by accident or not
     * at all. Check `balanced` per group — a false there means the books do not
     * foot, which is a finding, not a display problem.
     *
     * Accounts with no movement and no balance are dropped.
     *
     * @authenticated
     *
     * @queryParam as_of date Balances as at this date, inclusive. Defaults to all time. Example: 2026-12-31
     *
     * @response 200 scenario="Success" {"data":{"as_of":"2026-12-31","currencies":{"EGP":{"total_debit":"1240000.00","total_credit":"1240000.00","balanced":true,"rows":[{"account":{"id":12,"code":"1300","name":"المخزون"},"debit":"480000.00","credit":"50000.00","balance":"430000.00"}]}}},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorizePermission('trial_balance.view');

        $asOf = $this->date($request, 'as_of');

        return $this->respond([
            'as_of' => $asOf?->toDateString(),
            'currencies' => ReportSerializer::normalize(
                $this->trialBalance->grouped($asOf)->all(),
            ),
        ]);
    }

    /**
     * General ledger for one account
     *
     * دفتر الأستاذ — every posted movement on an account in date order, each
     * row carrying the running balance after it, plus the opening balance the
     * period started from and the closing balance it ended on.
     *
     * The opening balance is the account's stored opening figure plus every
     * movement strictly before `from`, which is what makes a period statement
     * stand on its own rather than only making sense read from the beginning
     * of time.
     *
     * @authenticated
     *
     * @queryParam account_id integer required The account to read. Example: 12
     * @queryParam from date Inclusive. Example: 2026-01-01
     * @queryParam to date Inclusive. Example: 2026-06-30
     *
     * @response 200 scenario="Success" {"data":{"account":{"id":12,"code":"1300","name":"المخزون"},"from":"2026-01-01","to":"2026-06-30","opening_balance":"0.00","closing_balance":"430000.00","totals":{"debit":"480000.00","credit":"50000.00"},"rows":[{"date":"2026-03-04","entry_number":"JE-202603-0009","debit":"480000.00","credit":"0.00","balance":"480000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function generalLedger(Request $request): JsonResponse
    {
        $this->authorizePermission('general_ledger.view');

        $account = Account::query()->findOrFail($request->integer('account_id'));
        [$from, $to] = $this->period($request);

        return $this->respond([
            'account' => ReportSerializer::normalize($account),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'opening_balance' => number_format($this->ledger->openingBalance($account, $from), 2, '.', ''),
            'closing_balance' => number_format($this->ledger->closingBalance($account, $to), 2, '.', ''),
            'totals' => ReportSerializer::normalize($this->ledger->totals($account, $from, $to)),
            'rows' => ReportSerializer::normalize($this->ledger->for($account, $from, $to)->all()),
        ]);
    }

    /**
     * Journal daybook
     *
     * دفتر اليومية التحليلي — posted entries as rows against accounts as
     * columns, the analytical layout an accountant reads a month in.
     *
     * `accounts` is the column set, `rows` the entries with one cell per
     * column, and `column_totals` the footer. Passing `account_ids` pins the
     * columns to the accounts you care about instead of letting the period
     * decide; without it the columns are whatever moved.
     *
     * @authenticated
     *
     * @queryParam from date Inclusive. Example: 2026-01-01
     * @queryParam to date Inclusive. Example: 2026-01-31
     * @queryParam account_ids string Comma-separated account ids to pin the columns to. Example: 12,31,44
     * @queryParam currency string Example: EGP
     *
     * @response 200 scenario="Success" {"data":{"accounts":[{"id":12,"code":"1300","name":"المخزون"}],"rows":[{"entry":{"id":77,"type":"JournalEntry"},"cells":[{"debit":"40000.00","credit":"0.00"}],"total_debit":"40000.00","total_credit":"40000.00"}],"column_totals":[{"debit":"40000.00","credit":"0.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function journalDaybook(Request $request): JsonResponse
    {
        $this->authorizePermission('journal_daybook.view');

        [$from, $to] = $this->period($request);

        $accountIds = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $request->query('account_ids', '')),
        )));

        return $this->respond(ReportSerializer::normalize($this->daybook->build(
            $from,
            $to,
            $accountIds,
            $request->query('currency') === null ? null : (string) $request->query('currency'),
        )));
    }

    /**
     * Income statement
     *
     * قائمة الدخل — net sales, cost of sales, gross profit, then other revenue
     * and expenses down to net profit. Every line carries the accounts behind
     * it, so a reader can see what a figure is made of without a second call.
     *
     * Note how foreign-exchange differences are handled: one NET figure, routed
     * to whichever side it fell on. It appears as `fx_gain` under revenues or
     * `fx_loss` under expenses, never both — showing a gain and a loss for the
     * same period would double-count the same movement.
     *
     * @authenticated
     *
     * @queryParam from date Inclusive. Example: 2026-01-01
     * @queryParam to date Inclusive. Example: 2026-12-31
     *
     * @response 200 scenario="Success" {"data":{"net_sales":{"sales":"2400000.00","returns":"50000.00","net":"2350000.00"},"cost_of_sales":"1400000.00","gross_profit":"950000.00","revenues":{"total":"20000.00"},"expenses":{"total":"310000.00"},"net_profit":"660000.00","from":"2026-01-01","to":"2026-12-31"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorizePermission('income_statement.view');

        [$from, $to] = $this->period($request);

        return $this->respond(ReportSerializer::normalize($this->income->build($from, $to)));
    }

    /**
     * Balance sheet
     *
     * الميزانية — fixed assets at cost / accumulated / net, working capital,
     * and the funding that pays for it all.
     *
     * Read `balanced` first. The sheet is only trustworthy if total investment
     * equals total funding; when it does not, `difference` says by how much,
     * and that is a finding to chase rather than a rounding artefact to hide.
     *
     * `period_from` decides the window the period's profit is computed over,
     * which is the figure carried inside equity. It defaults to the start of
     * `as_of`'s year.
     *
     * @authenticated
     *
     * @queryParam as_of date The date the sheet is drawn at. Defaults to today. Example: 2026-12-31
     * @queryParam period_from date Start of the period whose profit sits in equity. Defaults to the start of that year. Example: 2026-01-01
     *
     * @response 200 scenario="Success" {"data":{"as_of":"2026-12-31","working_capital":"820000.00","total_investment":"1960000.00","funding":{"total":"1960000.00"},"balanced":true,"difference":"0.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorizePermission('balance_sheet.view');

        $asOf = $this->date($request, 'as_of');
        $periodFrom = $this->date($request, 'period_from');

        return $this->respond(ReportSerializer::normalize(
            $this->balanceSheet->build($asOf, $periodFrom),
        ));
    }

    /**
     * Cash flow statement
     *
     * قائمة التدفقات النقدية — net profit adjusted for non-cash items, then
     * working capital, investing and financing, down to the net change in cash.
     *
     * The field to check is `reconciled`. The statement derives a closing cash
     * figure and compares it against the cash the ledger actually holds; when
     * the two disagree, `reconciliation_difference` says by how much. A cash
     * flow statement that does not reconcile is not a presentation issue.
     *
     * `add_back_non_cash` reports which convention the platform is configured
     * for, so a reader knows whether depreciation was added back or netted.
     *
     * @authenticated
     *
     * @queryParam from date Inclusive. Defaults to the start of `to`'s year. Example: 2026-01-01
     * @queryParam to date Inclusive. Defaults to today. Example: 2026-12-31
     *
     * @response 200 scenario="Success" {"data":{"from":"2026-01-01","to":"2026-12-31","net_profit":"660000.00","operating_cash":"720000.00","net_change":"310000.00","opening_cash":"200000.00","derived_closing_cash":"510000.00","actual_closing_cash":"510000.00","reconciled":true,"reconciliation_difference":"0.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorizePermission('cash_flow_statement.view');

        [$from, $to] = $this->period($request);

        return $this->respond(ReportSerializer::normalize($this->cashFlow->build($from, $to)));
    }

    /**
     * Operating statement
     *
     * قائمة التشغيل — the cost-of-sales accounts and their total for the
     * period. It is the figure the income statement's `cost_of_sales` is taken
     * from, published on its own so the number can be opened up.
     *
     * @authenticated
     *
     * @queryParam from date Inclusive. Example: 2026-01-01
     * @queryParam to date Inclusive. Example: 2026-12-31
     *
     * @response 200 scenario="Success" {"data":{"total":"1400000.00","from":"2026-01-01","to":"2026-12-31","rows":[{"account":{"id":51,"code":"5010","name":"خامات"},"amount":"1200000.00"}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function operatingStatement(Request $request): JsonResponse
    {
        $this->authorizePermission('operating_statement.view');

        [$from, $to] = $this->period($request);

        return $this->respond(ReportSerializer::normalize($this->operating->build($from, $to)));
    }

    /**
     * An operation's cost file
     *
     * ملف تكلفة العملية — the budget, what materials and ledger expenses have
     * been charged to it, what it has invoiced and collected, and the profit
     * that leaves.
     *
     * This is the one report that pulls from every module at once: materials
     * come from posted issue vouchers net of returns, expenses from journal
     * lines tagged to the operation, revenue from its deliveries and
     * collections from its payments. Which is exactly why it is published as
     * one figure set rather than left to a client to assemble — five screens
     * assembling it independently would give five different profits.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 4
     *
     * @response 200 scenario="Success" {"data":{"project_id":4,"estimated_budget":"2000000.00","materials_cost":"430000.00","ledger_expenses":"60000.00","revenue":"2350000.00","received":"1500000.00","total_cost":"490000.00","profit":"1860000.00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function operationCost(Project $project): JsonResponse
    {
        $this->authorizePermission('operations.view_cost');

        return $this->respond(array_merge(
            ['project_id' => $project->id, 'project_code' => $project->code],
            ReportSerializer::normalize($this->operationCost->breakdown($project)),
        ));
    }

    /**
     * An operation's timeline
     *
     * الخط الزمنى للعملية — every stage the operation has reached and when,
     * with the ones it has not reached marked unreached rather than omitted.
     *
     * Keeping the unreached stages in the list is the point: a timeline that
     * only showed what happened could not show what is next, which is the
     * question anyone opening it is actually asking.
     *
     * @authenticated
     *
     * @urlParam project integer required The operation id. Example: 4
     *
     * @response 200 scenario="Success" {"data":{"project_id":4,"current_stage":{"key":"delivered","label":"Delivered"},"stages":[{"key":"offer","label":"Offer","at":"2026-02-01","reached":true,"days_from_start":0},{"key":"installed","label":"Installed","at":null,"reached":false,"days_from_start":null}]},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function operationTimeline(Project $project): JsonResponse
    {
        $this->authorizePermission('operations.overview');

        return $this->respond([
            'project_id' => $project->id,
            'project_code' => $project->code,
            'current_stage' => ReportSerializer::normalize($this->timeline->currentStage($project)),
            'stages' => ReportSerializer::normalize($this->timeline->for($project)->all()),
        ]);
    }

    /**
     * Read one optional date query parameter.
     */
    private function date(Request $request, string $key): ?Carbon
    {
        $raw = trim((string) $request->query($key, ''));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $key => ["{$key} must be a valid date."],
            ]);
        }
    }

    /**
     * Read `from` and `to`, and refuse a period that runs backwards.
     *
     * A backwards period would return an empty report, and an empty report
     * looks exactly like a true answer — "this account had no movement" rather
     * than "you asked the question wrong".
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function period(Request $request): array
    {
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');

        if ($from !== null && $to !== null && $to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => [__('errors.api.report_period_invalid')],
            ]);
        }

        return [$from, $to];
    }
}
