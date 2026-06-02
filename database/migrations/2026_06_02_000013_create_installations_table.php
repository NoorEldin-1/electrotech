<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرحلة التركيب — Installations (سلايد 2: "عند وجود تركيبات يتم تسليم البضاعة
 * وبداية التركيب وتحميل جميع المصاريف على مركز التكلفة"). Tracks the
 * installation stage of an operation; the expenses themselves are booked to GL
 * account 5020 (مصروفات تركيب) tagged to the operation and surfaced by the cost
 * center.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('delivery_voucher_id')->nullable()->constrained('delivery_vouchers')->nullOnDelete();
            $table->string('status')->default('pending')->index(); // App\Enums\InstallationStatus
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
