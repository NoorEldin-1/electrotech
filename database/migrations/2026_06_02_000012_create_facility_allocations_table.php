<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توزيع التسهيل على العمليات — Facility allocations (سلايد 1: "وتحليلها على
 * العمليات"). Each row reserves part of a facility's limit for an operation;
 * the facility's available amount = limit − Σ active allocations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->decimal('allocated_amount', 15, 2);
            $table->date('allocated_at');
            $table->string('status')->default('active')->index(); // active | released
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['credit_facility_id', 'status']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_allocations');
    }
};
