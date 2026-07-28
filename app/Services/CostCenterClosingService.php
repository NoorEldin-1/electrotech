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
 * is still sitting in the inventory control account even though the goods have
 * physically left for the customer — so on delivery that balance is carried to
 * cost of goods sold with a balanced entry: Dr COGS (tagged to the operation) /
 * Cr inventory.
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
                'inventory' => config('operations.inventory_account_code', '1300'),
            ]));
        }

        return DB::transaction(function () use ($project, $voucher, $user, $automatic, $amount, $cogs, $inventory) {
            $entry = $this->postJournal(
                project: $project,
                amount: $amount,
                debitAccountId: $cogs->id,
                creditAccountId: $inventory->id,
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
     * a reversing entry (Dr inventory / Cr COGS) plus a negative closing row —
     * the unclosed balance comes back on its own and the audit trail stays whole.
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

        if ($cogs === null || $inventory === null) {
            throw new \RuntimeException(__('errors.cost_center.accounts_missing', [
                'cogs' => config('operations.cogs_account_code', '5070'),
                'inventory' => config('operations.inventory_account_code', '1300'),
            ]));
        }

        $project = $closing->project;
        $amount = (float) $closing->amount;

        return DB::transaction(function () use ($closing, $project, $amount, $cogs, $inventory, $user, $reason) {
            // Mirror image of the closing entry: inventory takes the value back.
            $entry = $this->postJournal(
                project: $project,
                amount: $amount,
                debitAccountId: $inventory->id,
                creditAccountId: $cogs->id,
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
     * Build and post the balanced entry. The cost-of-goods-sold side carries the
     * project tag — analysing COGS by operation is the whole point of the cost
     * centre — while inventory, a pooled control account, stays untagged.
     */
    private function postJournal(
        Project $project,
        float $amount,
        int $debitAccountId,
        int $creditAccountId,
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
        $entry->lines()->create([
            'account_id' => $debitAccountId,
            'project_id' => $tagProjectOnDebit ? $project->id : null,
            'direction' => AccountDirection::Debit,
            'amount' => $amount,
        ]);

        $entry->lines()->create([
            'account_id' => $creditAccountId,
            'project_id' => $tagProjectOnDebit ? null : $project->id,
            'direction' => AccountDirection::Credit,
            'amount' => $amount,
        ]);

        $this->journals->post($entry->fresh('lines'));

        return $entry->refresh();
    }

    /**
     * @return array{0: ?Account, 1: ?Account} [cogs, inventory]
     */
    private function resolveAccounts(): array
    {
        return [
            Account::where('code', config('operations.cogs_account_code', '5070'))->first(),
            Account::where('code', config('operations.inventory_account_code', '1300'))->first(),
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
