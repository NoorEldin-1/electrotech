<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WarehouseType;
use App\Models\Item;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * TESTING AID — inject stock straight into a warehouse so a flow can be tried
 * without walking its whole upstream chain (e.g. testing delivery-voucher
 * invoicing without running a manufacturing order to fill finished goods).
 *
 * Refuses to run in production: in real operation finished goods may only be
 * created by completing a manufacturing order (WorkOrderService::complete),
 * and raw stock only by an addition voucher. This command bypasses both.
 */
class SeedTestStock extends Command
{
    protected $signature = 'stock:seed-test
        {item : Item id, reference code or name}
        {quantity=100 : Quantity to add}
        {--warehouse=finished_goods : raw_materials | work_in_progress | finished_goods}';

    protected $description = '[Local/testing only] Add stock for an item directly into a warehouse.';

    public function handle(InventoryService $inventory): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production: stock must come from vouchers and manufacturing orders.');

            return self::FAILURE;
        }

        $warehouse = WarehouseType::tryFrom((string) $this->option('warehouse'));

        if (! $warehouse) {
            $this->error('Unknown warehouse. Use one of: '
                . implode(', ', array_column(WarehouseType::cases(), 'value')));

            return self::FAILURE;
        }

        $needle = (string) $this->argument('item');

        $item = Item::query()
            ->when(ctype_digit($needle), fn ($query) => $query->orWhere('id', (int) $needle))
            ->orWhere('sku', $needle)
            ->orWhere('name', $needle)
            ->first();

        if (! $item) {
            $this->error("No item matches '{$needle}'.");
            $this->line('Available items:');
            Item::query()->get(['id', 'sku', 'name'])
                ->each(fn (Item $i) => $this->line("  [{$i->id}] {$i->sku} — {$i->name}"));

            return self::FAILURE;
        }

        $quantity = (float) $this->argument('quantity');

        $inventory->addStock(
            item: $item,
            quantity: $quantity,
            notes: 'Seeded for testing via stock:seed-test',
            warehouse: $warehouse,
            unitCost: (float) $item->unit_cost,
        );

        $item->load('inventories');

        $this->info("Added {$quantity} of [{$item->id}] {$item->name} to {$warehouse->value}.");
        $this->table(
            ['Warehouse', 'On hand'],
            collect(WarehouseType::cases())
                ->map(fn (WarehouseType $w) => [$w->value, number_format($item->quantityIn($w), 4)])
                ->all()
        );

        return self::SUCCESS;
    }
}
