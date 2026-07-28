<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\JournalStatus;
use App\Enums\PaymentDirection;
use App\Enums\PurchaseOrderStatus;
use App\Enums\VoucherStatus;
use App\Models\IssueVoucher;
use App\Models\JournalEntryLine;
use App\Models\Project;

/**
 * الإدارة العامة — Operation Cost Center (سلايد 1: "العملية = مركز تكلفة").
 *
 * Aggregation layer (a View, not a stored table — same philosophy as
 * TrialBalanceService): for a given operation (project) it sums all cost
 * components across departments and computes profitability. Single source of
 * truth; nothing is written here.
 *
 * Cost model (no double counting):
 *   total cost   = materials issued (posted issue vouchers via work orders)
 *                + GL expense lines tagged to the operation
 *   revenue      = active delivery vouchers for the operation
 *   profit       = revenue − total cost
 * Purchase orders are reported as a *reference* figure only (the actual
 * material cost is the issued value, to avoid counting a purchase twice).
 */
class OperationCostService
{
    /**
     * Full cost breakdown for an operation.
     *
     * @return array{
     *     estimated_budget: float,
     *     materials_cost: float,
     *     ledger_expenses: float,
     *     purchases_reference: float,
     *     total_cost: float,
     *     revenue: float,
     *     profit: float,
     *     margin_percent: float|null,
     *     inventory_consumed: float,
     *     closed_to_cogs: float,
     *     unclosed_cost: float
     * }
     */
    public function breakdown(Project $project): array
    {
        $materials = $this->materialsCost($project);
        $ledgerExpenses = $this->ledgerExpenses($project);
        $purchases = $this->purchasesReference($project);
        $revenue = $this->revenue($project);
        $closings = app(CostCenterClosingService::class);

        $totalCost = $materials + $ledgerExpenses;
        $profit = $revenue - $totalCost;

        return [
            'estimated_budget' => (float) $project->estimated_budget,
            'materials_cost' => $materials,
            'ledger_expenses' => $ledgerExpenses,
            'installation_expenses' => $this->installationExpenses($project),
            'purchases_reference' => $purchases,
            'total_cost' => $totalCost,
            'revenue' => $revenue,
            'received' => $this->received($project),
            'profit' => $profit,
            'margin_percent' => $revenue > 0.0 ? ($profit / $revenue) * 100 : null,
            // سلايد 12 — the closing state of the cost centre. `inventory_consumed`
            // is the accounting base (issued − returned − written off), which is
            // deliberately not `materials_cost`: natural loss stays loaded on the
            // operation while its value has already left the inventory account.
            'inventory_consumed' => $closings->inventoryConsumed($project),
            'closed_to_cogs' => $closings->closedValue($project),
            'unclosed_cost' => $closings->unclosedBalance($project),
        ];
    }

    /**
     * Materials consumed by the operation = posted issue vouchers whose work
     * order belongs to the project. Equals the running `project.actual_cost`.
     */
    public function materialsCost(Project $project): float
    {
        return (float) IssueVoucher::query()
            ->where('status', VoucherStatus::Posted->value)
            ->whereHas('workOrder', fn ($q) => $q->where('project_id', $project->id))
            ->sum('total_value');
    }

    /**
     * Operating / installation / general expenses posted to the GL and tagged
     * to this operation (debit lines on posted entries against expense accounts).
     */
    public function ledgerExpenses(Project $project): float
    {
        return (float) JournalEntryLine::query()
            ->where('project_id', $project->id)
            ->where('direction', AccountDirection::Debit->value)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalStatus::Posted->value))
            ->whereHas('account', fn ($q) => $q
                ->where('type', AccountType::Expense->value)
                // Cost of goods sold is the RESULT of closing this cost centre
                // (سلايد 12), not an input to it: its value is the material
                // already counted in materialsCost(). Counting the closing
                // entry here as well would double the operation's total cost.
                ->where('code', '!=', config('operations.cogs_account_code', '5070')))
            ->sum('amount');
    }

    /**
     * Reference only: total value of the operation's purchase orders
     * (excluding cancelled). Not added into total cost.
     */
    public function purchasesReference(Project $project): float
    {
        return (float) $project->purchaseOrders()
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->sum('total_amount');
    }

    /**
     * Revenue = active delivery vouchers delivered to the customer for the
     * operation.
     */
    public function revenue(Project $project): float
    {
        return (float) $project->deliveryVouchers()
            ->where('status', DeliveryVoucherStatus::Active->value)
            ->sum('total_value');
    }

    /**
     * Cash actually collected against the operation (incoming payments).
     * Reported alongside revenue; not part of total cost.
     */
    public function received(Project $project): float
    {
        return (float) $project->operationPayments()
            ->where('direction', PaymentDirection::Incoming->value)
            ->sum('amount');
    }

    /**
     * Installation expenses (سلايد 2): project-tagged debit GL lines on the
     * configured installation expense account. A subset of ledgerExpenses —
     * surfaced separately, never added on top of total cost.
     */
    public function installationExpenses(Project $project): float
    {
        $code = config('operations.installation_account_code', '5020');

        return (float) JournalEntryLine::query()
            ->where('project_id', $project->id)
            ->where('direction', AccountDirection::Debit->value)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalStatus::Posted->value))
            ->whereHas('account', fn ($q) => $q->where('code', $code))
            ->sum('amount');
    }

    public function totalCost(Project $project): float
    {
        return $this->materialsCost($project) + $this->ledgerExpenses($project);
    }

    public function profit(Project $project): float
    {
        return $this->revenue($project) - $this->totalCost($project);
    }
}
