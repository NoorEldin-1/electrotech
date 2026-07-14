<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تقرير الإنتاج والفاقد — تحويله إلى أساس قيمي (مكتب ادارة المشروعات.pptx سلايد
 * 9): يُلتقط عند إتمام أمر التصنيع اسم العملية + المخطط (قيمة طلب التصنيع) +
 * المنتج الفعلي (قيمة أمر الصرف)، والفاقد = الفرق بينهما. لقطة ثابتة وقت
 * الإتمام حتى لا تتغيّر بتغيّر التكاليف لاحقاً. See WorkOrderService::complete().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_entries', function (Blueprint $table) {
            $table->string('operation_name')->nullable()->after('output_item_id');
            $table->decimal('planned_material_cost', 15, 2)->default(0)->after('scrap_quantity')
                ->comment('المخطط — قيمة طلب التصنيع وقت الإتمام');
            $table->decimal('actual_material_cost', 15, 2)->default(0)->after('planned_material_cost')
                ->comment('المنتج الفعلي — قيمة أمر الصرف وقت الإتمام');
        });
    }

    public function down(): void
    {
        Schema::table('production_entries', function (Blueprint $table) {
            $table->dropColumn([
                'operation_name',
                'planned_material_cost',
                'actual_material_cost',
            ]);
        });
    }
};
