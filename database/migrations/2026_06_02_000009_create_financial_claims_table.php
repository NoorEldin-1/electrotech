<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المطالبة المالية — Financial Claims (سلايد 2: "المطالبة المالية" بعد اتمام
 * التوريد والتركيب). A claim raised against the customer for an operation,
 * moving draft → submitted → collected. Auto-numbered FC-YYYYMM-XXXX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('claim_date');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status')->default('draft')->index(); // App\Enums\ClaimStatus
            $table->string('description')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_claims');
    }
};
