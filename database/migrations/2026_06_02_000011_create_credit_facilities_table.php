<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسهيلات الائتمانية — Credit facilities (سلايد 1: "مراقبة التسهيلات
 * وتحليلها على العمليات"). A facility (bank/customer credit line) with a limit,
 * optionally tied to a GL liability account (e.g. 2070 تسهيلات أبو ظبي) and a
 * customer. Usage is tracked via per-operation allocations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('limit_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('active')->index(); // App\Enums\FacilityStatus
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_facilities');
    }
};
