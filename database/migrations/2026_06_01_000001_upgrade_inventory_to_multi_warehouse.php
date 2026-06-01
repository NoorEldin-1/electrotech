<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — turn the single-row-per-item inventory into per-(item, warehouse)
 * balances, and give the transaction ledger a warehouse + unit_cost so every
 * movement records WHERE it happened and at WHAT value.
 *
 * The existing data already has exactly one row per item (item_id was unique),
 * so no data migration is needed for `inventories` beyond swapping the unique
 * constraint. Existing transactions are back-filled with the item's home
 * warehouse and the item's current unit_cost as a reasonable historical value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Swap the unique(item_id) for a composite unique(item_id, warehouse_type)
        //    so the same item can hold stock in more than one warehouse.
        Schema::table('inventories', function (Blueprint $table) {
            $table->unique(['item_id', 'warehouse_type'], 'inventories_item_warehouse_unique');
        });

        // The FK on item_id keeps a usable index via the composite above
        // (item_id is the leftmost column), so dropping the single unique is safe.
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropUnique('inventories_item_id_unique');
        });

        // 2) Add warehouse + valuation to the transaction ledger.
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('warehouse_type')->nullable()->after('type')
                ->comment('raw_materials, work_in_progress, finished_goods');
            $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity');
            $table->index(['warehouse_type', 'type']);
        });

        // 3) Back-fill historical transactions with the item's home warehouse
        //    and current unit_cost so reports over old data stay coherent.
        //    Correlated subqueries keep this portable across MySQL & SQLite
        //    (the test database). On a fresh install there are no rows yet,
        //    so this is a no-op there.
        DB::statement(<<<'SQL'
            UPDATE inventory_transactions
            SET warehouse_type = (
                SELECT CASE i.type
                    WHEN 'finished_good' THEN 'finished_goods'
                    WHEN 'semi_finished' THEN 'work_in_progress'
                    ELSE 'raw_materials'
                END
                FROM items i WHERE i.id = inventory_transactions.item_id
            )
            WHERE warehouse_type IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE inventory_transactions
            SET unit_cost = (
                SELECT i.unit_cost FROM items i WHERE i.id = inventory_transactions.item_id
            )
            WHERE unit_cost IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex(['warehouse_type', 'type']);
            $table->dropColumn(['warehouse_type', 'unit_cost']);
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->unique('item_id', 'inventories_item_id_unique');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropUnique('inventories_item_warehouse_unique');
        });
    }
};
