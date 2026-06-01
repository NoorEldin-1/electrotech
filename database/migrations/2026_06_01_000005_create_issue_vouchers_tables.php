<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إذن صرف — Issue Voucher. At the start of manufacturing, raw materials are
 * moved from the raw warehouse into work-in-progress against this document
 * (referencing the work order = طلب تصنيع). Posting transfers the stock and
 * loads its value onto the operation (project = cost centre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_number')->unique();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->date('voucher_date');
            $table->string('status')->default('draft')->index();
            $table->decimal('total_value', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('issue_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_voucher_id')->constrained('issue_vouchers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_voucher_lines');
        Schema::dropIfExists('issue_vouchers');
    }
};
