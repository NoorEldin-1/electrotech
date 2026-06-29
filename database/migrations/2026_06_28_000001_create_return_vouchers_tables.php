<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إذن ارتداد — Return Voucher. During manufacturing, raw material that was
 * pulled into work-in-progress but never consumed (scrap / فضلات) is returned
 * to the raw warehouse under a DIFFERENT code (the linked scrap variant item).
 * Posting deducts it from the original item's WIP balance, adds it to the
 * scrap item's raw stock, and REVERSES its value off the operation
 * (project + work order) — scrap is not loaded onto the operation cost.
 *
 * Mirrors issue_vouchers (see 2026_06_01_000005); the post() logic is its
 * inverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_vouchers', function (Blueprint $table) {
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

        Schema::create('return_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_voucher_id')->constrained('return_vouchers')->cascadeOnDelete();
            // The ORIGINAL raw item being returned; posting routes the quantity
            // into its linked scrap variant.
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_voucher_lines');
        Schema::dropIfExists('return_vouchers');
    }
};
