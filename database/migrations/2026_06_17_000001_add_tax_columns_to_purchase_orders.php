<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing modifications — slide 3: a purchase order now records its money
 * breakdown (subtotal + 14% VAT − 1% profit-withholding). `apply_profit_tax`
 * snapshots whether the supplier was subject to the 1% deduction at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('subtotal', 15, 2)->default(0)->after('supplier_contact');
            $table->decimal('vat_amount', 15, 2)->default(0)->after('subtotal');
            $table->decimal('profit_tax_amount', 15, 2)->default(0)->after('vat_amount');
            $table->boolean('apply_profit_tax')->default(true)->after('profit_tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'vat_amount', 'profit_tax_amount', 'apply_profit_tax']);
        });
    }
};
