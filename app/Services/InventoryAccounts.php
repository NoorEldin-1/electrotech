<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WarehouseType;
use App\Models\Account;

/**
 * The split inventory accounts (Final_Modifications سلايد 2–3, ruling R1).
 *
 * The single "1300 المخزون" account is kept as the parent, and each store has
 * its own account underneath it:
 *
 *   1310 مخزن الخامات              — raw materials store
 *   1320 مخزن تحت التشغيل          — work in progress
 *   1330 مخزن المنتجات التامة      — finished goods
 *
 * Every getter returns the Account (or null when the chart has no active
 * account with that code) so a caller can decide for itself how to degrade —
 * the posting services log + skip, the valuation page shows a notice. Nothing
 * is memoised on purpose: a chart edited (or seeded) after this object was
 * built must be picked up by the very next lookup.
 */
class InventoryAccounts
{
    /** Raw-materials store (1310 by default). */
    public function rawMaterials(): ?Account
    {
        return $this->find((string) config('operations.raw_materials_account_code', '1310'));
    }

    /** Work-in-progress store (1320 by default). */
    public function wip(): ?Account
    {
        return $this->find((string) config('operations.wip_account_code', '1320'));
    }

    /** Finished-goods store (1330 by default). */
    public function finishedGoods(): ?Account
    {
        return $this->find((string) config('operations.finished_goods_account_code', '1330'));
    }

    /**
     * The legacy single inventory account (1300). It is the parent of the three
     * above and no longer a posting target of the new flows; only the
     * depreciation and cost-centre-closing flows still fall back to it when the
     * split accounts are absent from an old chart.
     */
    public function legacy(): ?Account
    {
        return $this->find((string) config('operations.inventory_account_code', '1300'));
    }

    /**
     * The payables control credited when goods are received (ruling R5).
     */
    public function suppliers(): ?Account
    {
        return $this->find((string) config('operations.suppliers_account_code', '2010'));
    }

    /**
     * The account that carries a physical warehouse's stock.
     */
    public function forWarehouse(WarehouseType $warehouse): ?Account
    {
        return match ($warehouse) {
            WarehouseType::RawMaterials => $this->rawMaterials(),
            WarehouseType::WorkInProgress => $this->wip(),
            WarehouseType::FinishedGoods => $this->finishedGoods(),
        };
    }

    /**
     * The three configured codes, keyed by store — the keys are stable, the
     * values come from config/operations.php.
     *
     * @return array{raw_materials: string, wip: string, finished_goods: string}
     */
    public function codes(): array
    {
        return [
            'raw_materials' => (string) config('operations.raw_materials_account_code', '1310'),
            'wip' => (string) config('operations.wip_account_code', '1320'),
            'finished_goods' => (string) config('operations.finished_goods_account_code', '1330'),
        ];
    }

    /**
     * Only an ACTIVE account counts: the statements ignore inactive accounts,
     * so a posting to one would unbalance them against the ledger.
     */
    private function find(string $code): ?Account
    {
        return Account::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }
}
