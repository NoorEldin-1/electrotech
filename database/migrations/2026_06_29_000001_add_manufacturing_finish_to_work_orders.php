<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "انتهاء التصنيع" — a stage-independent finish signal (التصنيع.pptx سلايد 2):
 * a button to mark manufacturing done as a whole, *regardless of the QA stages*,
 * so the system can record the manufacturing time and tell every department the
 * product is ready for delivery.
 *
 * Kept separate from actual_end_date (which WorkOrderService::complete() sets
 * after the QA gate) on purpose: this is the readiness signal, not the
 * financial/inventory close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('manufacturing_finished_at')->nullable()->after('actual_end_date');
            $table->unsignedInteger('manufacturing_duration_minutes')->nullable()->after('manufacturing_finished_at');
            $table->foreignId('manufacturing_finished_by')->nullable()->after('manufacturing_duration_minutes')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manufacturing_finished_by');
            $table->dropColumn(['manufacturing_finished_at', 'manufacturing_duration_minutes']);
        });
    }
};
