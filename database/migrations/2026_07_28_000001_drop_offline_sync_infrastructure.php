<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the offline-first sync subsystem (the "Operator Console" feature)
 * from the schema. Counterpart to the application-code removal of
 * App\Sync\**, routes/sync.php, config/sync.php and public/console/**.
 *
 * WHY THIS MIGRATION IS MANDATORY, not cosmetic
 * ---------------------------------------------
 * 2026_05_20_100001 added `uuid CHAR(36) NOT NULL UNIQUE` to seven core
 * business tables. Nothing in the database supplies a default for it — the
 * value came exclusively from App\Sync\Observers\SyncableObserver, which is
 * now gone. Leaving the column in place would make *every* INSERT into
 * projects / items / inventories / boms / bom_items / work_orders /
 * inventory_transactions fail with "Field 'uuid' doesn't have a default
 * value". So the columns must go in the same deploy as the code.
 *
 * The two original sync migrations (100001 / 100002) are intentionally left
 * on disk: production has already recorded them in the `migrations` table,
 * and deleting them would leave orphan rows and break a fresh `migrate` run
 * of this file. They build the columns, this one takes them away.
 *
 * Every operation is guarded with hasTable/hasColumn/hasIndex so the file is
 * idempotent and safe on a fresh database, a partially migrated one, and a
 * production database that has run the full sync stack.
 */
return new class extends Migration
{
    /**
     * Tables that carried the sync metadata columns.
     * Mirrors SYNCABLE_TABLES in 2026_05_20_100001.
     */
    private const SYNCABLE_TABLES = [
        'projects',
        'items',
        'inventories',
        'boms',
        'bom_items',
        'work_orders',
        'inventory_transactions',
    ];

    private const SYNC_COLUMNS = [
        'uuid',
        'record_version',
        'client_updated_at',
        'sync_origin',
        'synced_at',
    ];

    /**
     * Standalone tables owned solely by the sync subsystem.
     * Dropped child-first so no FK is left dangling mid-run.
     */
    private const SYNC_TABLES = [
        'sync_operation_log',
        'sync_conflicts',
        'sync_tombstones',
        'device_tokens',
    ];

    public function up(): void
    {
        foreach (self::SYNC_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        foreach (self::SYNCABLE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Indexes first. MySQL would cascade the drop when the column
            // goes, but being explicit keeps the intent readable and avoids
            // relying on engine-specific behaviour.
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasIndex($table, "{$table}_uuid_unique")) {
                    $t->dropUnique("{$table}_uuid_unique");
                }

                if (Schema::hasIndex($table, "{$table}_sync_cursor_idx")) {
                    $t->dropIndex("{$table}_sync_cursor_idx");
                }
            });

            $present = array_values(array_filter(
                self::SYNC_COLUMNS,
                fn (string $column): bool => Schema::hasColumn($table, $column)
            ));

            if ($present === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($present) {
                $t->dropColumn($present);
            });
        }
    }

    /**
     * Deliberately irreversible.
     *
     * Rolling this back would recreate empty sync scaffolding for a feature
     * whose application code no longer exists — the columns would sit
     * unpopulated (and `uuid` would be NOT NULL with no writer, immediately
     * breaking inserts again). If the offline-first subsystem is ever
     * revived, it should ship its own forward migration.
     */
    public function down(): void
    {
        // No-op by design. See the docblock above.
    }
};
