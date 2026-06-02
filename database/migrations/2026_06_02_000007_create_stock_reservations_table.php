<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حجز الكمية للعملية — Stock reservations for an operation (سلايد 1:
 * "حجز الكمية وخروجها باذن صرف من المخازن"). Wires the existing
 * InventoryService hold/release mechanism to a project: each row holds a
 * quantity of an item in a warehouse for an operation until it is issued
 * (released).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('warehouse_type'); // App\Enums\WarehouseType
            $table->decimal('quantity', 14, 4);
            $table->string('status')->default('active')->index(); // App\Enums\ReservationStatus
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
