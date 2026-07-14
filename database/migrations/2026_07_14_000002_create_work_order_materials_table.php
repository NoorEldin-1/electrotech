<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول خامات أمر التصنيع — per-work-order material lines (سلايد 6: المرونة
 * والتعديل اليدوي). When a finished good is selected on a work order, the
 * standard BOM quantities are COPIED here (scaled by the planned quantity),
 * then the office may edit them freely for THIS order without touching the
 * product's fixed standard recipe. The issue voucher is built from these lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_materials');
    }
};
