<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "اعتماد مدير مكتب المشروعات" (مكتب ادارة المشروعات.pptx سلايد 5) — the PMO
 * manager approval gate that moves a work order from Draft to Pending. Records
 * who approved and when, mirroring the QA gate's audit trail (qa_approved_by).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('order_approved_by')->nullable()->after('qa_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('order_approved_at')->nullable()->after('order_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_approved_by');
            $table->dropColumn('order_approved_at');
        });
    }
};
