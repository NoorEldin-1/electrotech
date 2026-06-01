<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * زر إجراء — completing a work order produces the finished product into the
 * finished-goods warehouse, consumes its work-in-progress materials, and
 * records a production entry capturing the planned-vs-actual comparison and
 * the extracted loss (الفاقد).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // The finished item produced by this work order (nullable: some
            // work orders are services / sub-assemblies without a catalog SKU).
            $table->foreignId('output_item_id')->nullable()->after('bom_id')
                ->constrained('items')->nullOnDelete();
        });

        Schema::create('production_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('output_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->date('entry_date');
            $table->decimal('planned_quantity', 14, 4)->default(0);
            $table->decimal('produced_quantity', 14, 4)->default(0);
            $table->decimal('scrap_quantity', 14, 4)->default(0)->comment('Extracted loss (الفاقد)');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_entries');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('output_item_id');
        });
    }
};
