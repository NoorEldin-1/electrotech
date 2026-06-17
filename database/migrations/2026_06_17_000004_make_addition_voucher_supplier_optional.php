<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing slide 9: an addition voucher (إذن إضافة) does NOT always come from
 * a registered supplier — some receipts have no invoice or purchase order. The
 * supplier link becomes optional, with a free-text `supplier_name` fallback;
 * the supplier ledger is only posted when a real supplier is linked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addition_vouchers', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('addition_vouchers', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->change();
            $table->string('supplier_name')->nullable()->after('supplier_id');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('addition_vouchers', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_name');
        });

        Schema::table('addition_vouchers', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable(false)->change();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
        });
    }
};
