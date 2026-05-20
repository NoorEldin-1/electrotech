<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync columns added to every syncable table:
 *
 *   uuid              - char(36) unique. The cross-system identity that survives
 *                       being authored on a client and accepted by the server.
 *                       The integer primary key cannot serve this role because
 *                       it is allocated by the database, so an offline-created
 *                       record has no id yet.
 *
 *   record_version    - bigint, monotonically incremented on every server-side
 *                       write. Provides a happens-before relation we can use
 *                       for last-writer-wins arbitration without trusting any
 *                       client's wall clock.
 *
 *   client_updated_at - the originating client's wall clock at the moment the
 *                       record was authored / modified offline. Stored for
 *                       audit and tie-breaking, but NEVER trusted alone for
 *                       conflict resolution (wall clocks lie under load).
 *
 *   sync_origin       - the device_id (uuid) of the client that authored the
 *                       last write. Lets us identify echoed-back writes during
 *                       pull so the client can avoid clobbering its own
 *                       in-flight edits.
 *
 *   synced_at         - server timestamp of last accepted sync touch. The pull
 *                       cursor is keyed on this column, NOT updated_at, because
 *                       updated_at may be manipulated by application logic
 *                       (e.g. soft-touch on a related record).
 *
 * IMPORTANT: We do NOT backfill uuids in a separate pass. The Syncable
 * observer in App\Sync\Concerns\Syncable generates a uuid on every save where
 * one is missing, so existing rows get a uuid the first time they are updated.
 * For tables that need an immediate uuid (anything we plan to expose via the
 * sync API right away), we backfill in this migration.
 */
return new class extends Migration
{
    /**
     * Tables that participate in the offline-first sync protocol.
     * Order matters only for the backfill loop — referenced tables first.
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

    public function up(): void
    {
        foreach (self::SYNCABLE_TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                // Some tables already have a uuid via package conventions —
                // guard each column individually rather than the whole block.
                if (! Schema::hasColumn($table, 'uuid')) {
                    $t->char('uuid', 36)->nullable()->after('id');
                }
                if (! Schema::hasColumn($table, 'record_version')) {
                    $t->unsignedBigInteger('record_version')->default(1);
                }
                if (! Schema::hasColumn($table, 'client_updated_at')) {
                    $t->timestamp('client_updated_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'sync_origin')) {
                    $t->char('sync_origin', 36)->nullable()->comment('device_id that authored the last write');
                }
                if (! Schema::hasColumn($table, 'synced_at')) {
                    $t->timestamp('synced_at')->nullable();
                }
            });

            // Backfill uuids for existing rows. Doing this here (rather than
            // lazily in the observer) means the sync API can immediately
            // expose every existing record by uuid — no half-syncable state.
            \DB::table($table)
                ->whereNull('uuid')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        \DB::table($table)
                            ->where('id', $row->id)
                            ->update(['uuid' => (string) \Illuminate\Support\Str::uuid()]);
                    }
                });

            // Now that backfill is done, enforce non-null + uniqueness +
            // a covering index for the pull-cursor query.
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->char('uuid', 36)->nullable(false)->change();
                $t->unique('uuid', "{$table}_uuid_unique");
                $t->index(['synced_at', 'id'], "{$table}_sync_cursor_idx");
            });
        }
    }

    public function down(): void
    {
        foreach (self::SYNCABLE_TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_uuid_unique");
                $t->dropIndex("{$table}_sync_cursor_idx");
                $t->dropColumn(['uuid', 'record_version', 'client_updated_at', 'sync_origin', 'synced_at']);
            });
        }
    }
};
