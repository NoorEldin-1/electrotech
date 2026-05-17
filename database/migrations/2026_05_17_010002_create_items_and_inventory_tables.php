<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique()->index();
            $table->string('type')->index()->comment('raw_material, finished_good, semi_finished, consumable');
            $table->string('unit')->comment('pcs, kg, m, m2, liter, set, roll, sheet, box, ton');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->decimal('minimum_stock', 12, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('warehouse_type')->default('raw_materials')
                ->comment('raw_materials, work_in_progress, finished_goods');
            $table->decimal('on_hand_quantity', 14, 4)->default(0);
            $table->decimal('on_hold_quantity', 14, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index()->comment('in, out, hold, release');
            $table->decimal('quantity', 14, 4);
            $table->nullableMorphs('reference');
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users');
            $table->timestamps();


            $table->index(['item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('items');
    }
};
