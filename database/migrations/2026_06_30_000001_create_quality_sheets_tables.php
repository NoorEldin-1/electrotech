<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ورقة الجودة — Quality Sheet (التصنيع سلايد 2 سفلي + سلايد 3). A structured
 * manufacturing inspection sheet per work order: operation header data
 * (conductor type, poles, operation name) plus a grid of test rows (بنود).
 * The QA department fills the results and signs (qa_filled_*), then the
 * factory manager gives final approval (factory_approved_*) which alerts every
 * department that the operation has finished manufacturing.
 *
 * "يجب عملها على البرنامج وطباعتها من خلال البرنامج" — hence a structured model
 * (printed via QualitySheetPdfController), not an attached scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('sheet_number')->unique();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->date('test_date')->nullable();

            // Operation header data (سلايد 3: نوع الموصل / عدد الأقطاب / اسم العملية).
            $table->string('conductor_type')->nullable();
            $table->string('connection_type')->nullable();
            $table->string('cross_section')->nullable();
            $table->unsignedInteger('poles_count')->nullable();
            $table->string('operation_name')->nullable();

            $table->string('status')->default('draft')->index();

            // QA department fills the results and signs.
            $table->foreignId('qa_filled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('qa_filled_at')->nullable();
            $table->text('qa_inspector_notes')->nullable();

            // Factory manager final approval (اعتماد نهائي من مدير المصنع).
            $table->foreignId('factory_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('factory_approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('quality_sheet_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_sheet_id')->constrained('quality_sheets')->cascadeOnDelete();
            $table->unsignedInteger('line_no')->nullable();
            $table->string('label')->nullable();
            $table->string('piece_number')->nullable();
            $table->string('assembly')->nullable();
            $table->string('visual_quality')->nullable();
            $table->string('required_size')->nullable();

            // Insulation / continuity test columns, exactly as on the sheet image.
            $table->string('test_pe_l123n')->nullable();
            $table->string('test_fe_l123n')->nullable();
            $table->string('test_n_l12l3')->nullable();
            $table->string('test_l1_l2l3')->nullable();
            $table->string('test_l2_l3')->nullable();

            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_sheet_lines');
        Schema::dropIfExists('quality_sheets');
    }
};
