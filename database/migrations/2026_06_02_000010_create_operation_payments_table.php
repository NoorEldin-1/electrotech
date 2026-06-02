<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعات النقدية والمقبوضات — Operation payments (سلايد 1: "استلام المالي
 * ورصدها في ملف الأوامر التوريد" + "إجراءات الدفعات النقدية"). Cash received
 * from / paid for an operation. May optionally generate a balanced GL entry
 * (Dr treasury / Cr customers for incoming) and be allocated to a financial
 * claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('financial_claim_id')->nullable()->constrained('financial_claims')->nullOnDelete();
            $table->string('direction'); // App\Enums\PaymentDirection
            $table->string('method'); // App\Enums\PaymentMethod
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete(); // treasury / bank
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->nullOnDelete(); // the other GL side
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EGP');
            $table->date('payment_date');
            $table->string('reference')->nullable(); // supply order / cheque no.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'direction']);
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_payments');
    }
};
