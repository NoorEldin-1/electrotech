<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إذن إهلاك — Depreciation / Write-off Voucher (التصنيع سلايد 6). Manufacturing
 * loss (هالك) is taken out of work-in-progress, which lowers the item's balance
 * and value on its item card, and is carried to a loss account via a balanced
 * journal entry. The `loss_type` drives the accounting:
 *   - abnormal (غير طبيعي): reversed off the operation (لا يمكن تحميلها على
 *     العملية) → Dr loss account / Cr inventory.
 *   - natural (طبيعي): stays loaded on the operation (يُحمَّل على العملية) →
 *     Dr operating expenses / Cr inventory.
 *
 * Mirrors return_vouchers (see 2026_06_28_000001); post() adds the GL leg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_number')->unique();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->date('voucher_date');
            $table->string('loss_type')->default('abnormal')->index();
            $table->string('status')->default('draft')->index();
            $table->decimal('total_value', 14, 2)->default(0);
            // The GL entry created when the voucher is posted (null until posted,
            // or if the loss accounts are not present in the chart).
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('depreciation_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depreciation_voucher_id')->constrained('depreciation_vouchers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_voucher_lines');
        Schema::dropIfExists('depreciation_vouchers');
    }
};
