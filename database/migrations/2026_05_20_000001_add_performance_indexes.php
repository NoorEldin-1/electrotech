<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds composite/secondary indexes for the hot query patterns identified
 * during the performance audit. Each index here corresponds to an actual
 * predicate or ORDER BY clause hit by a Filament resource, observer, or
 * cached widget — none are speculative.
 */
return new class extends Migration
{
    public function up(): void
    {
        // WorkOrder list: defaultSort created_at DESC + filter by status +
        // dashboard widget counts WHERE status = InProgress.
        Schema::table('work_orders', function (Blueprint $table) {
            $table->index(['status', 'project_id'], 'work_orders_status_project_idx');
            $table->index(['priority', 'status'], 'work_orders_priority_status_idx');
        });

        // PurchaseOrder list: filter by status, group by project for
        // project-level reporting.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index(['project_id', 'status'], 'purchase_orders_project_status_idx');
        });

        // BOM list: filter by status (e.g. only "approved" BOMs visible
        // in WorkOrder.bom_id dropdown), grouped by project.
        Schema::table('boms', function (Blueprint $table) {
            $table->index(['project_id', 'status'], 'boms_project_status_idx');
        });

        // InventoryTransaction list: defaultSort created_at DESC and date-
        // range filters. Without this MySQL filesorts every page render.
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->index('created_at', 'inventory_transactions_created_at_idx');
        });

        // Spatie activity_log: the event-filter SelectFilter scans by event
        // and the date-range filter scans by created_at. Both currently
        // full-table-scan on a table that grows linearly with traffic.
        Schema::table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->index('event', 'activity_log_event_idx');
            $table->index('created_at', 'activity_log_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex('work_orders_status_project_idx');
            $table->dropIndex('work_orders_priority_status_idx');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('purchase_orders_project_status_idx');
        });

        Schema::table('boms', function (Blueprint $table) {
            $table->dropIndex('boms_project_status_idx');
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex('inventory_transactions_created_at_idx');
        });

        Schema::table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropIndex('activity_log_event_idx');
            $table->dropIndex('activity_log_created_at_idx');
        });
    }
};
