<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إذن إضافة — Addition Voucher (Goods Receipt). Purchases land in the raw
 * materials warehouse against this document. When posted it adds stock and
 * credits the supplier's account with the invoice value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addition_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_number')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('invoice_number')->nullable();
            $table->decimal('invoice_value', 14, 2)->nullable()->comment('Financial value posted to the supplier');
            $table->date('voucher_date');
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('addition_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addition_voucher_id')->constrained('addition_vouchers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addition_voucher_lines');
        Schema::dropIfExists('addition_vouchers');
    }
};
