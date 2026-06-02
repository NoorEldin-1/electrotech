<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimate-vs-actual cost on the operating order (أمر التشغيل) — سلايد 2:
 * "المقارنة بين التكلفة الفعلية وأمر التشغيل".
 *
 *  - estimated_cost: a snapshot of the linked BOM total cost taken at WO
 *    creation, so the comparison stays stable even if item prices change.
 *  - actual_material_cost: accrued from posted issue vouchers for this WO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->decimal('estimated_cost', 15, 2)->default(0)->after('waste_quantity');
            $table->decimal('actual_material_cost', 15, 2)->default(0)->after('estimated_cost');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['estimated_cost', 'actual_material_cost']);
        });
    }
};
