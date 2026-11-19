<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\Account;
use App\Models\AdditionVoucher;
use App\Models\DepreciationVoucher;
use App\Models\IssueVoucher;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ReturnVoucher;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The automatic inventory journals (Final_Modifications سلايد 2–3, Task 7).
 *
 * Stock is booked to one account per store (see InventoryAccounts) and every
 * stock-moving document writes its own balanced, posted entry the moment it is
 * posted — in the same transaction as the stock movement:
 *
 *   إذن إضافة            Dr 1310 raw (per warehouse of the line)  / Cr 2010 suppliers
 *   إذن صرف              Dr 1320 WIP (tagged to the operation)    / Cr 1310
 *   إذن ارتداد           Dr 1310                                  / Cr 1320 (tagged)
 *   إذن إهلاك            Dr 5060 | 5010 loss                      / Cr 1320 (tagged)
 *   انتهاء التصنيع       Dr 1330 finished goods                   / Cr 1320 (tagged)
 *   تسليم العميل         Dr 5070 COGS (tagged)                    / Cr 1330
 *                        (CostCenterClosingService, not here)
 *
 * Guarantees, in order of importance:
 *
 *  - Idempotent per source document (G8). Every entry carries
 *    (source_type, source_id, source_event), unique in the database, so the
 *    same document cannot be booked twice however it is retried.
 *  - Never throws over a missing account. If a store account is absent from
 *    the chart the entry is skipped and logged — the stock movement still
 *    happens, the same philosophy as the loss journal and the cost-centre
 *    closing. (Anything else — an unbalanced entry, a database error — DOES
 *    propagate, so the document is rolled back rather than half booked.)
 *  - Posted entries are never deleted. A correction is a reversing entry
 *    (reverse()), source_event 'reversal:<event>'.
 *
 * What is tagged to the operation (project_id): ONLY the work-in-progress
 * (1320) lines. That gives the valuation page a per-operation WIP breakdown
 * straight from the ledger. The raw and finished-goods stores are pooled
 * control accounts and stay untagged — the same decision the cost-centre
 * closing already documents for inventory — and none of this reaches
 * OperationCostService::ledgerExpenses, which counts only expense accounts
 * (and now also excludes the three store accounts explicitly).
 */
class InventoryJournalService
{
    public const EVENT_POST = 'post';

    public const EVENT_FINISH = 'finish';

    public const REVERSAL_PREFIX = 'reversal:';

    /** Amounts below this are float/rounding noise, never a real posting. */
    private const EPSILON = 0.005;

    /** Tries at inserting an entry whose number collided with a concurrent one. */
    private const MAX_INSERT_ATTEMPTS = 3;

    public function __construct(
        private readonly InventoryAccounts $accounts,
        private readonly JournalEntryService $journals,
    ) {}

    // ------------------------------------------------------------ addition

    /**
     * إذن إضافة — the goods entered the store at their STOCK value
     * (Σ quantity × unit cost), so that is what the store account takes, split
     * by the warehouse each line lands in (a purchased finished good belongs on
     * 1330, not 1310). The credit is the supplier control account (ruling R5):
     * no PO or voucher in this system records a cash payment method, so the
     * treasury branch of R5 has nothing to key on and the liability is always
     * booked; a payment order settles it later.
     *
     * Deliberately NOT keyed on invoice_value: an invoice may carry VAT or a
     * price difference that must not be capitalised into stock — the
     * invoice-vs-receipt reconciliation (PurchaseInvoicingService) flags that
     * difference for the accountant instead. PurchaseInvoicingService itself
     * posts no journal (it only syncs the supplier sub-ledger row), so there is
     * no leg of its own to replace and nothing is booked twice.
     */
    public function recordAddition(AdditionVoucher $voucher): ?JournalEntry
    {
        $voucher->loadMissing('lines.item');

        $byWarehouse = [];

        foreach ($voucher->lines as $line) {
            $warehouse = $line->item->homeWarehouse();
            $byWarehouse[$warehouse->value] = ($byWarehouse[$warehouse->value] ?? 0.0)
                + (float) $line->quantity * (float) $line->unit_cost;
        }

        $lines = [];
        $total = 0.0;

        foreach ($byWarehouse as $warehouseKey => $value) {
            $amount = round($value, 2);

            if ($amount <= self::EPSILON) {
                continue;
            }

            $store = $this->accounts->forWarehouse(WarehouseType::from($warehouseKey));

            if ($store === null) {
                return $this->skipped($voucher, self::EVENT_POST, [$this->codeFor($warehouseKey)]);
            }

            $lines[] = $this->line($store, AccountDirection::Debit, $amount);
            $total += $amount;
        }

        if ($lines === []) {
            return null;
        }

        $suppliers = $this->accounts->suppliers();

        if ($suppliers === null) {
            return $this->skipped($voucher, self::EVENT_POST, [(string) config('operations.suppliers_account_code', '2010')]);
        }

        $lines[] = $this->line($suppliers, AccountDirection::Credit, round($total, 2));

        return $this->record(
            source: $voucher,
            event: self::EVENT_POST,
            lines: $lines,
            description: __('inventory_journals.addition', ['number' => $voucher->voucher_number]),
            documentNumber: $voucher->voucher_number,
            date: $voucher->voucher_date,
        );
    }

    // --------------------------------------------------------------- issue

    /**
     * إذن صرف — raw material moves into the operation: Dr WIP (tagged to the
     * operation) / Cr raw materials, at the voucher's value. Call AFTER the
     * voucher's total_value has been stored.
     */
    public function recordIssue(IssueVoucher $voucher): ?JournalEntry
    {
        $voucher->loadMissing('workOrder');

        $amount = round((float) $voucher->total_value, 2);

        if ($amount <= self::EPSILON || $voucher->workOrder === null) {
            return null;
        }

        $raw = $this->accounts->rawMaterials();
        [$store, $projectId, $storeCode] = $this->costStore($voucher->workOrder);

        if ($raw === null || $store === null) {
            return $this->skipped($voucher, self::EVENT_POST, array_filter([
                $raw === null ? $this->accounts->codes()['raw_materials'] : null,
                $store === null ? $storeCode : null,
            ]));
        }

        return $this->record(
            source: $voucher,
            event: self::EVENT_POST,
            lines: [
                $this->line($store, AccountDirection::Debit, $amount, $projectId),
                $this->line($raw, AccountDirection::Credit, $amount),
            ],
            description: __('inventory_journals.issue', [
                'number' => $voucher->voucher_number,
                'wo' => $voucher->workOrder->wo_number,
            ]),
            documentNumber: $voucher->voucher_number,
            date: $voucher->voucher_date,
        );
    }

    // -------------------------------------------------------------- return

    /**
     * إذن ارتداد — the exact mirror of the issue: Dr raw materials / Cr WIP
     * (tagged), at the voucher's value. Call AFTER total_value has been stored.
     */
    public function recordReturn(ReturnVoucher $voucher): ?JournalEntry
    {
        $voucher->loadMissing('workOrder');

        $amount = round((float) $voucher->total_value, 2);

        if ($amount <= self::EPSILON || $voucher->workOrder === null) {
            return null;
        }

        $raw = $this->accounts->rawMaterials();
        [$store, $projectId, $storeCode] = $this->costStore($voucher->workOrder);

        if ($raw === null || $store === null) {
            return $this->skipped($voucher, self::EVENT_POST, array_filter([
                $raw === null ? $this->accounts->codes()['raw_materials'] : null,
                $store === null ? $storeCode : null,
            ]));
        }

        return $this->record(
            source: $voucher,
            event: self::EVENT_POST,
            lines: [
                $this->line($raw, AccountDirection::Debit, $amount),
                $this->line($store, AccountDirection::Credit, $amount, $projectId),
            ],
            description: __('inventory_journals.return', [
                'number' => $voucher->voucher_number,
                'wo' => $voucher->workOrder->wo_number,
            ]),
            documentNumber: $voucher->voucher_number,
            date: $voucher->voucher_date,
        );
    }

    // ------------------------------------------------------------ write-off

    /**
     * إذن إهلاك — the written-off value leaves the order's cost store and lands
     * on the loss account (abnormal → manufacturing loss, natural → operating
     * expenses; the caller resolves which). The loss debit is NOT tagged to the
     * operation, so the write-off never reaches ledgerExpenses; the credit is a
     * WIP line and is tagged like every other WIP line.
     *
     * A depreciation voucher always takes stock out of the WIP warehouse, so
     * the credit is the order's cost store (1320, or 1330 once the order has
     * been finished in the books). If the chart has not been seeded with the
     * split accounts yet it falls back to the legacy 1300 — the one flow where
     * the old behaviour is kept, so an un-migrated chart still books its loss.
     */
    public function recordWriteOff(DepreciationVoucher $voucher, Account $loss, float $total): ?JournalEntry
    {
        $voucher->loadMissing('workOrder');

        $amount = round($total, 2);

        if ($amount <= self::EPSILON || $voucher->workOrder === null) {
            return null;
        }

        [$store, $projectId, $storeCode] = $this->costStore($voucher->workOrder, legacyFallback: true);

        if ($store === null) {
            return $this->skipped($voucher, self::EVENT_POST, [$storeCode]);
        }

        return $this->record(
            source: $voucher,
            event: self::EVENT_POST,
            lines: [
                $this->line($loss, AccountDirection::Debit, $amount),
                $this->line($store, AccountDirection::Credit, $amount, $projectId),
            ],
            description: __('resources.depreciation_vouchers.journal_description', [
                'number' => $voucher->voucher_number,
                'wo' => $voucher->workOrder->wo_number,
            ]),
            documentNumber: $voucher->voucher_number,
            date: $voucher->voucher_date,
        );
    }

    // -------------------------------------------------------------- finish

    /**
     * انتهاء التصنيع — the order's accumulated material cost moves from WIP to
     * finished goods: Dr 1330 / Cr 1320 (tagged), at what the order's posted
     * issues, returns and write-offs ACTUALLY booked in 1320 (see
     * costBookedInWip()).
     *
     * It is deliberately not the voucher totals (costCarried()): a voucher
     * posted before this feature was deployed — or one whose entry was skipped
     * for a missing account — never debited 1320, so crediting 1320 for it
     * would drive the store negative. That unbooked part is settled by the
     * cost-centre closing against the legacy 1300 instead (unbookedCost()).
     *
     * Idempotent per work order. A zero amount (nothing booked in WIP yet)
     * posts nothing and leaves the order "not finished in the books", so the
     * next call — the completion of the order, or the closing of its cost
     * centre — settles it. From the moment the entry exists, later vouchers of
     * the order are booked straight to 1330 (see costStore()) so nothing is
     * ever left behind in WIP.
     */
    public function recordManufacturingFinish(WorkOrder $workOrder): ?JournalEntry
    {
        $existing = JournalEntry::query()->forSource($workOrder, self::EVENT_FINISH)->first();

        if ($existing !== null) {
            return $existing;
        }

        $wip = $this->accounts->wip();
        $finished = $this->accounts->finishedGoods();

        if ($wip === null || $finished === null) {
            // Nothing can have been booked to a store that is not in the chart;
            // only complain when the order does carry cost.
            if (round($this->costCarried($workOrder), 2) <= self::EPSILON) {
                return null;
            }

            return $this->skipped($workOrder, self::EVENT_FINISH, array_filter([
                $wip === null ? $this->accounts->codes()['wip'] : null,
                $finished === null ? $this->accounts->codes()['finished_goods'] : null,
            ]));
        }

        $amount = round($this->costBookedInWip($workOrder, $wip), 2);

        if ($amount <= self::EPSILON) {
            return null;
        }

        return $this->record(
            source: $workOrder,
            event: self::EVENT_FINISH,
            lines: [
                $this->line($finished, AccountDirection::Debit, $amount),
                $this->line($wip, AccountDirection::Credit, $amount, $workOrder->project_id),
            ],
            description: __('inventory_journals.finish', ['wo' => $workOrder->wo_number]),
            documentNumber: $workOrder->wo_number,
            date: $workOrder->manufacturing_finished_at ?? now(),
        );
    }

    /**
     * Settle every order of an operation that still carries cost in WIP, so
     * finished goods holds the WHOLE cost the closing is about to credit — an
     * order completed without ever pressing «انتهاء التصنيع», or one cancelled
     * after material was issued, would otherwise leave 1330 short.
     */
    public function settleOperation(Project $project): void
    {
        foreach ($project->workOrders()->get() as $workOrder) {
            $this->recordManufacturingFinish($workOrder);
        }
    }

    /**
     * Net material cost an order has accumulated: posted issues − posted
     * returns − posted write-offs. Same basis as
     * CostCenterClosingService::inventoryConsumed(), per work order. This is the
     * VOUCHER view of the cost — it includes vouchers that were posted before
     * the inventory journals existed and so never touched 1320; what the books
     * actually hold is costBookedInWip().
     */
    public function costCarried(WorkOrder $workOrder): float
    {
        $posted = fn (string $model): float => (float) $model::query()
            ->where('work_order_id', $workOrder->getKey())
            ->where('status', VoucherStatus::Posted->value)
            ->sum('total_value');

        return $posted(IssueVoucher::class)
            - $posted(ReturnVoucher::class)
            - $posted(DepreciationVoucher::class);
    }

    /**
     * What the order's posted vouchers have really put into work in progress:
     * the net debit (debits +, credits −) of the POSTED journal lines on the
     * WIP account whose entry points at one of the order's posted issue,
     * return or depreciation vouchers. An issue debits 1320, a return and a
     * write-off credit it, so this is "issues − returns − write-offs" restricted
     * to the vouchers that were booked in 1320.
     *
     * The entry's source_event is not filtered: a reversal entry shares its
     * voucher's source, so it nets the original off on its own. A voucher with
     * no entry (posted before deploy — ruling R2 — or skipped for a missing
     * account) contributes nothing, and neither does one booked to 1330 because
     * the order had already been finished in the books.
     *
     * @param  Account|null  $wip  the WIP account when the caller already resolved it
     */
    public function costBookedInWip(WorkOrder $workOrder, ?Account $wip = null): float
    {
        $wip ??= $this->accounts->wip();

        if ($wip === null) {
            return 0.0;
        }

        $net = 0.0;

        foreach ([IssueVoucher::class, ReturnVoucher::class, DepreciationVoucher::class] as $model) {
            $postedVouchers = $model::query()
                ->where('work_order_id', $workOrder->getKey())
                ->where('status', VoucherStatus::Posted->value)
                ->select('id');

            $net += $this->netDebitOnVouchers($wip, (new $model)->getMorphClass(), $postedVouchers);
        }

        return round($net, 2);
    }

    /**
     * The part of an operation's cost that never went through the inventory
     * journals: posted vouchers of its work orders that have no entry with
     * source_event 'post' — issues − returns − write-offs, the same basis as
     * CostCenterClosingService::inventoryConsumed() restricted to those
     * vouchers. These were stocked on the legacy 1300 (or never booked at all),
     * so the cost-centre closing credits that share to 1300, not to 1330, which
     * never received it (see CostCenterClosingService::close()).
     */
    public function unbookedCost(Project $project): float
    {
        $unbooked = function (string $model) use ($project): float {
            $instance = new $model;
            $table = $instance->getTable();

            return (float) $model::query()
                ->where('status', VoucherStatus::Posted->value)
                ->whereHas('workOrder', fn ($q) => $q->where('project_id', $project->getKey()))
                ->whereNotExists(fn ($q) => $q
                    ->select(DB::raw(1))
                    ->from('journal_entries')
                    ->whereColumn('journal_entries.source_id', $table.'.'.$instance->getKeyName())
                    ->where('journal_entries.source_type', $instance->getMorphClass())
                    ->where('journal_entries.source_event', self::EVENT_POST))
                ->sum('total_value');
        };

        return round(
            $unbooked(IssueVoucher::class)
            - $unbooked(ReturnVoucher::class)
            - $unbooked(DepreciationVoucher::class),
            2,
        );
    }

    /**
     * Has the order's cost been moved to finished goods in the books (and not
     * undone since)?
     */
    public function isFinishedInBooks(WorkOrder $workOrder): bool
    {
        return JournalEntry::query()->forSource($workOrder, self::EVENT_FINISH)->exists()
            && ! JournalEntry::query()
                ->forSource($workOrder, self::REVERSAL_PREFIX.self::EVENT_FINISH)
                ->exists();
    }

    // ------------------------------------------------------------ reversal

    /**
     * Undo what a document booked. A posted entry is immutable (G8), so the
     * correction is a NEW posted entry with every line flipped, linked to the
     * same source with event 'reversal:<event>'. Idempotent: reversing twice
     * returns the first reversal. Returns null when nothing was booked for the
     * document (e.g. the entry had been skipped for a missing account).
     */
    public function reverse(Model $source, string $event = self::EVENT_POST): ?JournalEntry
    {
        $original = JournalEntry::query()->forSource($source, $event)->with('lines')->first();

        if ($original === null) {
            return null;
        }

        $lines = $original->lines
            ->map(fn ($line): array => [
                'account_id' => (int) $line->account_id,
                'direction' => $line->direction === AccountDirection::Debit
                    ? AccountDirection::Credit
                    : AccountDirection::Debit,
                'amount' => (float) $line->amount,
                'project_id' => $line->project_id,
            ])
            ->values()
            ->all();

        return $this->record(
            source: $source,
            event: self::REVERSAL_PREFIX.$event,
            lines: $lines,
            description: __('inventory_journals.reversal', ['number' => $original->entry_number]),
            documentNumber: $original->document_number,
            date: now(),
        );
    }

    // ------------------------------------------------------------ internals

    /**
     * Which account carries an order's cost right now, whether its lines are
     * tagged to the operation, and the code that was looked up (for the log
     * when it is missing).
     *
     * Work in progress (1320, tagged) until the order's finish has been booked;
     * after that finished goods (1330, untagged) — a late issue, return or
     * write-off then lands where the cost already is instead of reopening WIP
     * for an order that is done. `$legacyFallback` lets the write-off flow fall
     * back to the old 1300 account on an un-migrated chart.
     *
     * @return array{0: ?Account, 1: ?int, 2: string}
     */
    private function costStore(WorkOrder $workOrder, bool $legacyFallback = false): array
    {
        $codes = $this->accounts->codes();

        if ($this->isFinishedInBooks($workOrder)) {
            $account = $this->accounts->finishedGoods() ?? ($legacyFallback ? $this->accounts->legacy() : null);

            return [$account, null, $codes['finished_goods']];
        }

        $wip = $this->accounts->wip();

        if ($wip !== null) {
            return [$wip, $workOrder->project_id, $codes['wip']];
        }

        return [$legacyFallback ? $this->accounts->legacy() : null, null, $codes['wip']];
    }

    /**
     * Net debit (debits − credits) an account carries from the POSTED entries
     * written for the given vouchers. Reversal entries share the source of the
     * entry they undo, so they are part of the sum and net it off.
     *
     * @param  EloquentBuilder<Model>  $vouchers  query selecting the ids of the vouchers (of one class) to look at
     */
    private function netDebitOnVouchers(Account $account, string $sourceType, EloquentBuilder $vouchers): float
    {
        $sum = fn (AccountDirection $direction): float => (float) JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->where('direction', $direction->value)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('status', JournalStatus::Posted->value)
                ->where('source_type', $sourceType)
                ->whereIn('source_id', $vouchers))
            ->sum('amount');

        return $sum(AccountDirection::Debit) - $sum(AccountDirection::Credit);
    }

    /**
     * @return array{account_id: int, direction: AccountDirection, amount: float, project_id: ?int}
     */
    private function line(Account $account, AccountDirection $direction, float $amount, ?int $projectId = null): array
    {
        return [
            'account_id' => $account->id,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'project_id' => $projectId,
        ];
    }

    /**
     * Create, fill and post one balanced entry for a source document — or
     * return the one that already exists for it.
     *
     * @param  array<int, array{account_id: int, direction: AccountDirection, amount: float, project_id: ?int}>  $lines
     */
    private function record(
        Model $source,
        string $event,
        array $lines,
        string $description,
        ?string $documentNumber,
        mixed $date,
    ): JournalEntry {
        $existing = JournalEntry::query()->forSource($source, $event)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($source, $event, $lines, $description, $documentNumber, $date): JournalEntry {
            // The unique index can refuse the insert for two different reasons,
            // and only one of them means "already booked":
            //  - the same (source, event) — a concurrent request booked this very
            //    document an instant ago: hand back ITS entry;
            //  - the entry number / serial — a concurrent request of ANOTHER
            //    document took the same sequence value: the lookup finds nothing,
            //    so the numbers are regenerated and the insert is retried.
            // The loop stays inside the transaction on purpose: a refused insert
            // aborts only its own statement, so the savepoint of an enclosing
            // voucher transaction is left intact.
            for ($attempt = 1; ; $attempt++) {
                try {
                    $entry = JournalEntry::create([
                        'entry_number' => JournalEntry::generateEntryNumber(DocumentType::Settlement),
                        'document_type' => DocumentType::Settlement,
                        'document_number' => $documentNumber,
                        'source_type' => $source->getMorphClass(),
                        'source_id' => $source->getKey(),
                        'source_event' => $event,
                        'entry_date' => $date ?? now(),
                        'description' => $description,
                        'status' => JournalStatus::Draft,
                        'currency' => 'EGP',
                        'created_by' => Auth::id(),
                    ]);

                    break;
                } catch (UniqueConstraintViolationException $e) {
                    // A locking read: under REPEATABLE READ a plain select could
                    // still be looking at the snapshot from before the winner
                    // committed and miss the very row that just refused us.
                    $winner = JournalEntry::query()->forSource($source, $event)->sharedLock()->first();

                    if ($winner !== null) {
                        return $winner;
                    }

                    if ($attempt >= self::MAX_INSERT_ATTEMPTS) {
                        throw $e;
                    }
                }
            }

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'project_id' => $line['project_id'],
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                ]);
            }

            $this->journals->post($entry->fresh('lines'));

            return $entry->refresh();
        });
    }

    /**
     * A store account is missing from the chart: leave the books untouched, say
     * so in the log, and let the stock movement go ahead.
     *
     * @param  array<int, string>  $missingCodes
     */
    private function skipped(Model $source, string $event, array $missingCodes): ?JournalEntry
    {
        Log::warning('Inventory journal skipped: account missing from the chart.', [
            'source' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'event' => $event,
            'missing_account_codes' => array_values($missingCodes),
        ]);

        return null;
    }

    private function codeFor(string $warehouse): string
    {
        $codes = $this->accounts->codes();

        return match ($warehouse) {
            WarehouseType::RawMaterials->value => $codes['raw_materials'],
            WarehouseType::WorkInProgress->value => $codes['wip'],
            default => $codes['finished_goods'],
        };
    }
}
