<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-offer history per project.
 *
 * Slide 4 of Sales_Department.md asks for "compare with last offer"
 * and recording the winning competitor — both require a separate
 * offers table, not a single column on `projects`.
 *
 * Each offer carries a financial_amount (mandatory) and an optional
 * technical_amount, with a per-project monotonically-increasing
 * version. When a project transitions to Active Operation, the
 * latest offer is marked is_winning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('financial_amount', 15, 2);
            $table->decimal('technical_amount', 15, 2)->nullable();
            $table->timestamp('submitted_at');
            $table->foreignId('submitted_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->boolean('is_winning')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'version'], 'project_offers_project_version_unique');
            $table->index(['project_id', 'is_winning'], 'project_offers_project_winning_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_offers');
    }
};
