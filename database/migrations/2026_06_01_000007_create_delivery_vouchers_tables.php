<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إذن تسليم — Delivery Voucher. Finished goods leave to the customer against
 * this document. It carries the transformer specs (plates, protection degree,
 * insulation voltage) and requires DUAL approval (technical + financial)
 * before it becomes active, deducts finished-goods stock, and debits the
 * customer's account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_number')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('supply_order_number')->nullable();
            $table->date('voucher_date');

            // Domain (transformer) specifications from slide 10.
            $table->unsignedInteger('plates_count')->nullable();
            $table->string('protection_degree')->nullable();
            $table->string('insulation_voltage')->nullable();

            $table->string('status')->default('draft')->index();
            $table->decimal('total_value', 14, 2)->default(0);

            // Dual approval signatures.
            $table->foreignId('technical_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('technical_approved_at')->nullable();
            $table->foreignId('financial_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('financial_approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('delivery_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_voucher_id')->constrained('delivery_vouchers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_voucher_lines');
        Schema::dropIfExists('delivery_vouchers');
    }
};
