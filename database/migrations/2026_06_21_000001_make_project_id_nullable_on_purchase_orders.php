<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement feedback: a purchase order need not belong to an operation.
 * Some POs are warehouse/stock purchases with no project link — leaving the
 * project empty makes the order a "warehouse" PO. So project_id becomes
 * nullable, and deleting a project now detaches its orders (nullOnDelete)
 * instead of cascading them away — the orders survive as warehouse POs and
 * their financial history is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Detached warehouse POs (project_id = null) would violate the NOT NULL
        // constraint on rollback; drop them so the column can revert cleanly.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        \Illuminate\Support\Facades\DB::table('purchase_orders')->whereNull('project_id')->delete();

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};
