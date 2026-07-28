<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إقفال مركز التكلفة — closing an operation's cost centre into the cost of
 * goods sold account (Financial Department سلايد 12: "وعند تسليم العميل بإذن
 * تسليم يتم اقفال مركز التكلفة فى حساب تكلفة البضاعة المباعة").
 *
 * One row per closing event, not one flag per operation: deliveries can be
 * partial and late costs can land after a first closing, so the centre is
 * closed incrementally — each closing carries the balance that was still
 * sitting in inventory at that moment, together with the journal entry it
 * posted (Dr COGS / Cr inventory).
 *
 * A posted journal entry is immutable, so a mistaken closing is undone by a
 * REVERSAL row (negative amount + reversing entry) that points back at the
 * closing it cancels via `reverses_id`. The unclosed balance is therefore
 * always: inventory consumed − SUM(amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_center_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The delivery that triggered the closing (سلايد 12). Null for a
            // manual closing recorded by finance.
            $table->foreignId('delivery_voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            // Set on a reversal row: the closing being undone.
            $table->foreignId('reverses_id')->nullable()->constrained('cost_center_closings')->nullOnDelete();
            // Positive for a closing, negative for a reversal.
            $table->decimal('amount', 14, 2);
            $table->boolean('is_automatic')->default(false);
            $table->string('notes')->nullable();
            // Null = posted by the system on delivery activation.
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->index(['project_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_center_closings');
    }
};
