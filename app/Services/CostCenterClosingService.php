<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Enums\VoucherStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Account;
use App\Models\CostCenterClosing;
use App\Models\DeliveryVoucher;
use App\Models\DepreciationVoucher;
use App\Models\IssueVoucher;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ReturnVoucher;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * إقفال مركز التكلفة — Financial Department سلايد 12: "وعند تسليم العميل بإذن
 * تسليم يتم اقفال مركز التكلفة فى حساب تكلفة البضاعة المباعة".
 *
 * The operation is the cost centre. Issue vouchers load material value onto it,
 * return and depreciation vouchers take value back out. Everything that stays
 * is still sitting in the finished-goods store (1330) even though the goods have
 * physically left for the customer — so on delivery that balance is carried to
 * cost of goods sold with a balanced entry: Dr COGS (tagged to the operation) /
 * Cr finished goods. (Before the inventory split it credited the single 1300
 * account, which is still the fallback on a chart that has not been seeded with
 * 1330 yet.) Orders still carrying cost in work in progress are settled to
 * finished goods first, so the credit always has the matching debit behind it.
 *
 * Cost that never went through the inventory journals — vouchers posted before
 * they existed (ruling R2: no backfill) — never reached 1330, so that share of
 * the closing is credited to the legacy 1300 it was stocked on instead:
 * Dr COGS / Cr 1330 (the booked part) / Cr 1300 (the unbooked part). Reversing
 * the closing mirrors every one of those credit lines.
 *
 * The closing base is deliberately NOT project.actual_cost. That figure is a
 * management number: natural manufacturing loss stays loaded on the operation
 * by design (التصنيع سلايد 5) while its value has already left the inventory
 * account through the depreciation entry. Closing on actual_cost would credit
 * inventory twice for that loss. The base here is the accounting truth:
 *
 *     issued − returned − written off − already closed
 */
class CostCenterClosingService
{
    /** Amounts below this are treated as fully closed (float/rounding noise). */
    private const EPSILON = 0.005;

    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly InventoryAccounts $inventoryAccounts,
        private readonly InventoryJournalService $inventoryJournals,
    ) {}

    /**
     * Value that moved from inventory into this operation and is still carried
     * there: posted issue vouchers, less returns and write-offs.
     */
    public function inventoryConsumed(Project $project): float
    {
        return round(
            $this->postedVoucherTotal(IssueVoucher::class, $project)
            - $this->postedVoucherTotal(ReturnVoucher::class, $project)
            - $this->postedVoucherTotal(DepreciationVoucher::class, $project),
            2,
        );
    }

    /**
     * Value already carried to cost of goods sold. Reversals are stored as
     * negative amounts, so a plain sum is the net closed value.
     */
    public function closedValue(Project $project): float
    {
        return round((float) $project->costCenterClosings()->sum('amount'), 2);
    }

    /** What is still waiting to be closed. */
    public function unclosedBalance(Project $project): float
    {
        return round($this->inventoryConsumed($project) - $this->closedValue($project), 2);
    }

    /** Fully closed: something was closed and nothing is left. */
    public function isClosed(Project $project): bool
    {
        return $this->closedValue($project) > self::EPSILON
            && $this->unclosedBalance($project) <= self::EPSILON;
    }

    /** Partially closed: something was closed but a balance remains. */
    public function isPartiallyClosed(Project $project): bool
    {
        return $this->closedValue($project) > self::EPSILON
            && $this->unclosedBalance($project) > self::EPSILON;
    }

    public function hasActiveDelivery(Project $project): bool
    {
        return $project->deliveryVouchers()
            ->where('status', DeliveryVoucherStatus::Active->value)
            ->exists();
    }

    /**
     * Work orders that can still load cost onto the centre. Draft, pending,
     * in-progress and QA-review orders are open; completed and cancelled ones
     * will never issue material again.
     */
    public function hasOpenWorkOrders(Project $project): bool
    {
        return $project->workOrders()
            ->whereNotIn('status', [
                WorkOrderStatus::Completed->value,
                WorkOrderStatus::Cancelled->value,
            ])
            ->exists();
    }

    /**
     * Close the operation's cost centre: carry the unclosed balance to cost of
     * goods sold. Called from the finance screen — every refusal is explicit so
     * the user learns why nothing was posted.
     *
     * @throws \RuntimeException if there is no delivery, nothing to close, or
     *                           the accounts are missing from the chart
     */
    public function close(
        Project $project,
        ?DeliveryVoucher $voucher = null,
        ?User $user = null,
        bool $automatic = false,
    ): CostCenterClosing {
        if (! $this->hasActiveDelivery($project)) {
            throw new \RuntimeException(__('errors.cost_center.no_delivery', ['operation' => $project->name]));
        }

        $amount = $this->unclosedBalance($project);

        if ($amount <= self::EPSILON) {
            throw new \RuntimeException(__('errors.cost_center.nothing_to_close', ['operation' => $project->name]));
        }

        [$cogs, $inventory] = $this->resolveAccounts();

        if ($cogs === null || $inventory === null) {
            throw new \RuntimeException(__('errors.cost_center.accounts_missing', [
                'cogs' => config('operations.cogs_account_code', '5070'),
                'inventory' => config('operations.finished_goods_account_code', '1330'),
            ]));
        }

        return DB::transaction(function () use ($project, $voucher, $user, $automatic, $amount, $cogs, $inventory) {
            // Move whatever the operation's orders still carry in work in
            // progress to finished goods, so the credit below is fully covered
            // (an order completed without «انتهاء التصنيع» would otherwise
            // leave 1330 short). A no-op for orders already finished.
            $this->inventoryJournals->settleOperation($project);

            // Cost that was never booked through the inventory journals
            // (vouchers posted before they existed) never reached 1330, so that
            // share is credited to the legacy 1300 it was stocked on; the rest
            // leaves finished goods.
            $legacyAccount = $this->inventoryAccounts->legacy();
            $legacy = $this->legacyShare($project, $amount, $inventory, $legacyAccount);
            $credits = [];

            if ($amount - $legacy > self::EPSILON) {
                $credits[] = ['account_id' => $inventory->id, 'amount' => round($amount - $legacy, 2)];
            }

            if ($legacy > self::EPSILON) {
                $credits[] = ['account_id' => $legacyAccount->id, 'amount' => $legacy];
            }

            $entry = $this->postJournal(
                project: $project,
                debits: [['account_id' => $cogs->id, 'amount' => $amount]],
                credits: $credits,
                documentNumber: $voucher?->voucher_number,
                description: __('resources.operations_cost.closing.journal_description', [
                    'operation' => $project->name,
                ]),
                userId: $user?->id,
            );

            return CostCenterClosing::create([
                'project_id' => $project->id,
                'delivery_voucher_id' => $voucher?->id,
                'journal_entry_id' => $entry->id,
                'amount' => $amount,
                'is_automatic' => $automatic,
                'closed_by' => $user?->id,
                'closed_at' => now(),
            ]);
        });
    }

    /**
     * سلايد 12 — the automatic path: a delivery voucher just went active, so
     * close the operation's cost centre.
     *
     * Two guards keep it honest. The centre is only closed when no work order
     * is still open (nothing more will be issued to it), otherwise cost would
     * be charged to sales before it finished accruing; and it never throws —
     * a missing account or an early delivery must not roll back a delivery
     * that physically happened. Finance can always close it by hand later.
     */
    public function closeOnDelivery(DeliveryVoucher $voucher): ?CostCenterClosing
    {
        if (! config('operations.auto_close_cost_center', true)) {
            return null;
        }

        $project = $voucher->project;

        if ($project === null || $this->hasOpenWorkOrders($project)) {
            return null;
        }

        try {
            return $this->close($project, $voucher, null, true);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Undo a closing. A posted journal entry is immutable, so the correction is
     * a reversing entry (Dr finished goods / Cr COGS) plus a negative closing row —
     * the unclosed balance comes back on its own and the audit trail stays whole.
     * The debit goes back to the very account the closing credited (read from the
     * closing's own entry), so a closing booked before the inventory split is
     * undone on 1300 and a later one on 1330.
     *
     * @throws \RuntimeException if the target is itself a reversal or already reversed
     */
    public function reverse(CostCenterClosing $closing, ?User $user = null, ?string $reason = null): CostCenterClosing
    {
        if ($closing->isReversal()) {
            throw new \RuntimeException(__('errors.cost_center.is_reversal'));
        }

        if ($closing->isReversed()) {
            throw new \RuntimeException(__('errors.cost_center.already_reversed'));
        }

        [$cogs, $inventory] = $this->resolveAccounts();

        // Every credit the closing posted comes back as a debit on the very
        // same account and amount — a closing split between 1330 and the legacy
        // 1300 is undone on both, a pre-split one on 1300 alone.
        $debits = ($closing->journalEntry?->lines()
            ->where('direction', AccountDirection::Credit->value)
            ->get(['account_id', 'amount']) ?? collect())
            ->map(fn ($line): array => ['account_id' => (int) $line->account_id, 'amount' => (float) $line->amount])
            ->values()
            ->all();

        if ($debits === [] && $inventory !== null) {
            // An entry that cannot be read back: fall back to the account the
            // closing would credit today.
            $debits = [['account_id' => $inventory->id, 'amount' => (float) $closing->amount]];
        }

        if ($cogs === null || $debits === []) {
            throw new \RuntimeException(__('errors.cost_center.accounts_missing', [
                'cogs' => config('operations.cogs_account_code', '5070'),
                'inventory' => config('operations.finished_goods_account_code', '1330'),
            ]));
        }

        $project = $closing->project;
        $amount = (float) $closing->amount;

        return DB::transaction(function () use ($closing, $project, $amount, $cogs, $debits, $user, $reason) {
            // Mirror image of the closing entry: inventory takes the value back.
            $entry = $this->postJournal(
                project: $project,
                debits: $debits,
                credits: [['account_id' => $cogs->id, 'amount' => $amount]],
                documentNumber: null,
                description: __('resources.operations_cost.closing.reversal_journal_description', [
                    'operation' => $project->name,
                ]),
                userId: $user?->id,
                tagProjectOnDebit: false,
            );

            return CostCenterClosing::create([
                'project_id' => $closing->project_id,
                'journal_entry_id' => $entry->id,
                'reverses_id' => $closing->id,
                'amount' => -$amount,
                'is_automatic' => false,
                'notes' => $reason,
                'closed_by' => $user?->id,
                'closed_at' => now(),
            ]);
        });
    }

    /**
     * How much of a closing of `$amount` is cost that never went through the
     * inventory journals and so must leave the legacy 1300 instead of 1330.
     *
     * unbookedCost() is what the operation's vouchers carry that was never
     * booked (posted before deploy — ruling R2 — or skipped). Earlier closings
     * of the same operation may already have used part of it, so what they
     * credited to 1300 (net of their reversals) is deducted, and the share can
     * never exceed the amount being closed. Zero when there is no separate 1330
     * to split from (an un-migrated chart credits 1300 alone) or no 1300 to
     * credit.
     */
    private function legacyShare(Project $project, float $amount, Account $inventory, ?Account $legacy): float
    {
        if ($legacy === null || $legacy->id === $inventory->id) {
            return 0.0;
        }

        $available = $this->inventoryJournals->unbookedCost($project) - $this->legacyCredited($project, $legacy);

        return round(min($amount, max(0.0, $available)), 2);
    }

    /**
     * What the operation's closings have credited to the legacy account so far,
     * net of reversals: a reversal entry debits exactly what it gives back, so
     * credits − debits over every closing entry of the operation is the net.
     */
    private function legacyCredited(Project $project, Account $legacy): float
    {
        $entryIds = $project->costCenterClosings()
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id')
            ->all();

        if ($entryIds === []) {
            return 0.0;
        }

        $sum = fn (AccountDirection $direction): float => (float) JournalEntryLine::query()
            ->whereIn('journal_entry_id', $entryIds)
            ->where('account_id', $legacy->id)
            ->where('direction', $direction->value)
            ->sum('amount');

        return round($sum(AccountDirection::Credit) - $sum(AccountDirection::Debit), 2);
    }

    /**
     * Build and post the balanced entry from its debit and credit lines, each
     * `['account_id' => int, 'amount' => float]`. The cost-of-goods-sold side
     * carries the project tag — analysing COGS by operation is the whole point
     * of the cost centre — while inventory, a pooled control account, stays
     * untagged.
     *
     * @param  array<int, array{account_id: int, amount: float}>  $debits
     * @param  array<int, array{account_id: int, amount: float}>  $credits
     */
    private function postJournal(
        Project $project,
        array $debits,
        array $credits,
        ?string $documentNumber,
        string $description,
        ?int $userId,
        bool $tagProjectOnDebit = true,
    ): JournalEntry {
        $entry = JournalEntry::create([
            'entry_number' => JournalEntry::generateEntryNumber(DocumentType::Settlement),
            'document_type' => DocumentType::Settlement,
            'document_number' => $documentNumber,
            'entry_date' => now(),
            'description' => $description,
            'status' => JournalStatus::Draft,
            'currency' => 'EGP',
            'created_by' => $userId,
        ]);

        // On a reversal the project tag rides the credit (COGS) line instead,
        // so the operation's tagged COGS nets back to zero.
        foreach ($debits as $line) {
            $entry->lines()->create([
                'account_id' => $line['account_id'],
                'project_id' => $tagProjectOnDebit ? $project->id : null,
                'direction' => AccountDirection::Debit,
                'amount' => $line['amount'],
            ]);
        }

        foreach ($credits as $line) {
            $entry->lines()->create([
                'account_id' => $line['account_id'],
                'project_id' => $tagProjectOnDebit ? null : $project->id,
                'direction' => AccountDirection::Credit,
                'amount' => $line['amount'],
            ]);
        }

        $this->journals->post($entry->fresh('lines'));

        return $entry->refresh();
    }

    /**
     * @return array{0: ?Account, 1: ?Account} [cogs, finished goods]
     */
    private function resolveAccounts(): array
    {
        return [
            Account::where('code', config('operations.cogs_account_code', '5070'))->first(),
            // 1330 — with the legacy 1300 as the fallback for a chart that has
            // not been seeded with the inventory split yet.
            $this->inventoryAccounts->finishedGoods() ?? $this->inventoryAccounts->legacy(),
        ];
    }

    /**
     * Posted total of a voucher type for every work order of the operation.
     *
     * @param  class-string<Model>  $model
     */
    private function postedVoucherTotal(string $model, Project $project): float
    {
        return (float) $model::query()
            ->where('status', VoucherStatus::Posted->value)
            ->whereHas('workOrder', fn ($q) => $q->where('project_id', $project->id))
            ->sum('total_value');
    }
}
