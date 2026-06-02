<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محاضر التسليم — Delivery Minutes (سلايد 2: "محاضر التسليم وارسالها لجميع
 * الأقسام"). A management document recorded on delivery and distributed to all
 * departments. Linked to the operation, and optionally to the delivery voucher
 * and customer it was generated from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_minutes', function (Blueprint $table) {
            $table->id();
            $table->string('minute_number')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('delivery_voucher_id')->nullable()->constrained('delivery_vouchers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('minute_date');
            $table->text('content')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('distributed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('minute_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_minutes');
    }
};
