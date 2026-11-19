<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountDirection;
use App\Enums\JournalStatus;
use App\Enums\WarehouseType;
use App\Models\Account;
use App\Models\DepreciationVoucher;
use App\Models\InventoryTransaction;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\Project;
use App\Models\ReturnVoucher;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تقييم المخزون — the inventory valuation report (Final_Modifications سلايد 2).
 *
 * Answers one question two ways and shows the gap:
 *
 *   ledger     — what the books say: the posted balance of each inventory
 *                account (1310 مخزن الخامات, 1320 مخزن تحت التشغيل, 1330
 *                مخزن المنتجات التامة) at a date. Read through
 *                AccountBalanceService, the same engine the balance sheet uses.
 *   stock card — what the warehouse says: the moving weighted-average value of
 *                every item in that warehouse, from StockCardService (the
 *                item card), cut off at the same date.
 *
 * The two only agree for stock that moved after the automatic inventory
 * journals went live: history before that was never journalled to the three
 * accounts (ruling R2 — no backfill), so the old 1300 balance and the first
 * reclassification entry are the usual reasons for a difference. That is why
 * the legacy 1300 balance is carried as its own line instead of being hidden,
 * and why a difference is reported rather than "fixed".
 *
 * The work-in-progress store is additionally broken down per operation: the
 * ledger side from journal lines tagged to the operation, the stock side from
 * the WIP warehouse movements traced back through their issue / return /
 * depreciation voucher or work order to the operation.
 *
 * Read-only. Never posts, never moves stock.
 */
class InventoryValuationService
{
    /** Differences below this are rounding noise, not a finding. */
    public const TOLERANCE = 0.01;

    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly StockCardService $stockCards,
        private readonly InventoryAccounts $accounts,
    ) {}

    /**
     * @return array{
     *     as_of: ?Carbon,
     *     stores: list<array{key: string, warehouse: WarehouseType, account: ?Account, ledger: ?float, stock_card: float, difference: ?float, reconciled: bool}>,
     *     legacy: array{account: ?Account, balance: float},
     *     totals: array{ledger: float, stock_card: float, difference: float, reconciled: bool},
     *     wip_operations: list<array{project: ?array{id: int, code: ?string, name: ?string}, ledger: float, stock_card: float, difference: float}>,
     *     missing_account_codes: list<string>
     * }
     */
    public function build(?Carbon $asOf = null): array
    {
        // The balance engine memoises per cut-off; a page object lives across
        // Livewire requests, so always start from fresh numbers.
        $this->balances->flush();

        $definitions = [
            'raw_materials' => [WarehouseType::RawMaterials, $this->accounts->rawMaterials()],
            'work_in_progress' => [WarehouseType::WorkInProgress, $this->accounts->wip()],
            'finished_goods' => [WarehouseType::FinishedGoods, $this->accounts->finishedGoods()],
        ];

        $stores = [];
        $wipValuation = ['value' => 0.0, 'by_project' => []];
        $wipStore = null;

        foreach ($definitions as $key => [$warehouse, $account]) {
            $valuation = $this->stockValuation($warehouse, $asOf, byProject: $warehouse === WarehouseType::WorkInProgress);

            if ($warehouse === WarehouseType::WorkInProgress) {
                $wipValuation = $valuation;
            }

            $ledger = $account ? $this->balances->rolledUpClosing($account, $asOf) : null;
            $stock = round($valuation['value'], 2);
            $difference = $ledger === null ? null : round($ledger - $stock, 2);

            $stores[] = $store = [
                'key' => $key,
                'warehouse' => $warehouse,
                'account' => $account,
                'ledger' => $ledger,
                'stock_card' => $stock,
                'difference' => $difference,
                'reconciled' => $difference !== null && abs($difference) < self::TOLERANCE,
            ];

            if ($warehouse === WarehouseType::WorkInProgress) {
                $wipStore = $store;
            }
        }

        $legacy = $this->legacyBalance($asOf);

        $ledgerTotal = round(
            (float) collect($stores)->sum(fn (array $store): float => (float) ($store['ledger'] ?? 0)) + $legacy['balance'],
            2,
        );
        $stockTotal = round((float) collect($stores)->sum('stock_card'), 2);
        $difference = round($ledgerTotal - $stockTotal, 2);

        return [
            'as_of' => $asOf,
            'stores' => $stores,
            'legacy' => $legacy,
            'totals' => [
                'ledger' => $ledgerTotal,
                'stock_card' => $stockTotal,
                'difference' => $difference,
                'reconciled' => abs($difference) < self::TOLERANCE,
            ],
            'wip_operations' => $this->wipOperations(
                $wipStore['account'],
                $wipValuation['by_project'],
                $wipStore['ledger'],
                $wipStore['stock_card'],
                $asOf,
            ),
            'missing_account_codes' => $this->missingAccountCodes($stores),
        ];
    }

    /**
     * Codes of the inventory accounts the chart does not have. Without them a
     * store has no ledger side to compare against — the report says so rather
     * than pretending the ledger is zero.
     *
     * @param  list<array{key: string, account: ?Account}>  $stores
     * @return list<string>
     */
    private function missingAccountCodes(array $stores): array
    {
        $configKeys = [
            'raw_materials' => ['raw_materials_account_code', '1310'],
            'work_in_progress' => ['wip_account_code', '1320'],
            'finished_goods' => ['finished_goods_account_code', '1330'],
        ];

        return collect($stores)
            ->filter(fn (array $store): bool => $store['account'] === null)
            ->map(fn (array $store): string => (string) config('operations.'.$configKeys[$store['key']][0], $configKeys[$store['key']][1]))
            ->values()
            ->all();
    }

    /**
     * The balance still sitting on the old, un-split inventory control account
     * (1300). Before the split everything was journalled there; Finance moves
     * it to 1310/1320/1330 with one manual entry (ruling R2), and until that
     * entry exists this line is what keeps the ledger total honest.
     *
     * @return array{account: ?Account, balance: float}
     */
    private function legacyBalance(?Carbon $asOf): array
    {
        $account = Account::query()
            ->where('code', config('operations.inventory_account_code', '1300'))
            ->first();

        return [
            'account' => $account,
            // The account's OWN balance — its three children are shown above.
            'balance' => $account ? $this->balances->closing($account, $asOf) : 0.0,
        ];
    }

    /**
     * Moving weighted-average value of every item in one warehouse at a date,
     * and — when asked — of the part of it that belongs to each operation.
     *
     * @return array{value: float, by_project: array<int, float>}
     */
    private function stockValuation(WarehouseType $warehouse, ?Carbon $asOf, bool $byProject): array
    {
        $itemIds = InventoryTransaction::query()
            ->where('warehouse_type', $warehouse->value)
            ->when($asOf, fn ($query) => $query->where('created_at', '<=', $asOf->copy()->endOfDay()))
            ->distinct()
            ->pluck('item_id');

        $value = 0.0;
        /** @var list<array{type: ?string, id: ?int, delta: float}> $movements */
        $movements = [];

        foreach ($itemIds->chunk(200) as $chunk) {
            foreach (Item::query()->whereIn('id', $chunk)->get() as $item) {
                $card = $this->stockCards->build($item, $warehouse, $asOf, withDetails: false);
                $value += $card['totals']['value'];

                if (! $byProject) {
                    continue;
                }

                foreach ($card['rows'] as $row) {
                    $movements[] = [
                        'type' => $row['reference_type'] ?? null,
                        'id' => $row['reference_id'] ?? null,
                        'delta' => (float) ($row['in_value'] ?? 0) - (float) ($row['out_value'] ?? 0),
                    ];
                }
            }
        }

        return [
            'value' => round($value, 2),
            'by_project' => $byProject ? $this->attributeToProjects($movements) : [],
        ];
    }

    /**
     * Sum WIP movement value per operation. A movement's operation is found
     * through its source document: issue / return / depreciation vouchers
     * point at a work order, and a work order at its project. Movements with
     * no traceable operation are simply not attributed (the caller reports the
     * remainder as unallocated).
     *
     * @param  list<array{type: ?string, id: ?int, delta: float}>  $movements
     * @return array<int, float> project id => value
     */
    private function attributeToProjects(array $movements): array
    {
        $voucherModels = [IssueVoucher::class, ReturnVoucher::class, DepreciationVoucher::class];
        $workOrderType = (new WorkOrder)->getMorphClass();

        $idsByType = [];

        foreach ($movements as $movement) {
            if ($movement['type'] !== null && $movement['id'] !== null) {
                $idsByType[$movement['type']][] = (int) $movement['id'];
            }
        }

        // voucher morph type => [voucher id => work order id]
        $workOrderOf = [];

        foreach ($voucherModels as $model) {
            $type = (new $model)->getMorphClass();

            if (! empty($idsByType[$type])) {
                $workOrderOf[$type] = $model::query()
                    ->whereIn('id', array_unique($idsByType[$type]))
                    ->pluck('work_order_id', 'id')
                    ->all();
            }
        }

        $workOrderIds = collect($idsByType[$workOrderType] ?? [])
            ->merge(collect($workOrderOf)->flatten())
            ->unique()
            ->values();

        $projectOf = $workOrderIds->isEmpty()
            ? []
            : WorkOrder::query()->whereIn('id', $workOrderIds)->pluck('project_id', 'id')->all();

        $byProject = [];

        foreach ($movements as $movement) {
            $workOrderId = match (true) {
                $movement['type'] === null || $movement['id'] === null => null,
                $movement['type'] === $workOrderType => (int) $movement['id'],
                default => $workOrderOf[$movement['type']][$movement['id']] ?? null,
            };

            $projectId = $workOrderId !== null ? ($projectOf[$workOrderId] ?? null) : null;

            if ($projectId !== null) {
                $byProject[(int) $projectId] = ($byProject[(int) $projectId] ?? 0.0) + $movement['delta'];
            }
        }

        return $byProject;
    }

    /**
     * Work-in-progress per operation: ledger (1320 lines tagged to the
     * operation) against stock card (WIP movements of the operation). Whatever
     * cannot be tied to an operation lands on a single unallocated row so the
     * column totals still equal the store totals above.
     *
     * @param  array<int, float>  $stockByProject
     * @return list<array{project: ?array{id: int, code: ?string, name: ?string}, ledger: float, stock_card: float, difference: float}>
     */
    private function wipOperations(?Account $wip, array $stockByProject, ?float $ledgerTotal, float $stockTotal, ?Carbon $asOf): array
    {
        $ledgerByProject = $wip ? $this->ledgerByProject($wip, $asOf) : [];

        $projectIds = collect(array_keys($ledgerByProject))->merge(array_keys($stockByProject))->unique()->values();
        $projects = Project::query()->whereIn('id', $projectIds)->get(['id', 'code', 'name'])->keyBy('id');

        $rows = [];

        foreach ($projectIds as $projectId) {
            $ledger = round($ledgerByProject[$projectId] ?? 0.0, 2);
            $stock = round($stockByProject[$projectId] ?? 0.0, 2);

            if (abs($ledger) < self::TOLERANCE && abs($stock) < self::TOLERANCE) {
                continue;
            }

            $project = $projects->get($projectId);

            $rows[] = [
                'project' => ['id' => (int) $projectId, 'code' => $project?->code, 'name' => $project?->name],
                'ledger' => $ledger,
                'stock_card' => $stock,
                'difference' => round($ledger - $stock, 2),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['project']['code'], (string) $b['project']['code']));

        $ledgerUnallocated = round(($ledgerTotal ?? 0.0) - (float) collect($rows)->sum('ledger'), 2);
        $stockUnallocated = round($stockTotal - (float) collect($rows)->sum('stock_card'), 2);

        if (abs($ledgerUnallocated) >= self::TOLERANCE || abs($stockUnallocated) >= self::TOLERANCE) {
            $rows[] = [
                'project' => null,
                'ledger' => $ledgerUnallocated,
                'stock_card' => $stockUnallocated,
                'difference' => round($ledgerUnallocated - $stockUnallocated, 2),
            ];
        }

        return $rows;
    }

    /**
     * Posted balance of the WIP account (and sub-accounts) per tagged
     * operation, up to the date. One grouped query: the balance engine answers
     * per account, not per cost-centre tag.
     *
     * @return array<int, float> project id => balance
     */
    private function ledgerByProject(Account $wip, ?Carbon $asOf): array
    {
        $accountIds = $this->balances->family($wip)->pluck('id')->all();

        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereNotNull('journal_entry_lines.project_id')
            ->when($asOf, fn ($query) => $query->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString()))
            ->groupBy('journal_entry_lines.project_id', 'journal_entry_lines.direction')
            ->select(
                'journal_entry_lines.project_id',
                'journal_entry_lines.direction',
                DB::raw('SUM(journal_entry_lines.amount) as total'),
            )
            ->get();

        $byProject = [];

        foreach ($rows as $row) {
            $signed = $row->direction === AccountDirection::Debit->value ? (float) $row->total : -(float) $row->total;
            $byProject[(int) $row->project_id] = ($byProject[(int) $row->project_id] ?? 0.0) + $wip->naturalSign() * $signed;
        }

        return $byProject;
    }
}
