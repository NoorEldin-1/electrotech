<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المواصفات الفنية لأمر التصنيع (مكتب ادارة المشروعات.pptx سلايد 2–3). مكتب
 * ادارة المشروعات يؤلّف هذه المواصفات عند كتابة أمر التصنيع، ثم تُنسَخ لقطةً
 * إلى ورقة الجودة عند إنشائها (بيانات العملية تُملأ تلقائياً بناءً على أمر
 * التصنيع). See App\Services\QualitySheetService::specSnapshotFrom().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('conductor_type')->nullable()->after('description');
            $table->string('cross_section')->nullable()->after('conductor_type');
            $table->string('cross_section_e')->nullable()->after('cross_section');
            $table->string('external_body')->nullable()->after('cross_section_e');
            $table->string('protection_degree')->nullable()->after('external_body');
            $table->string('paint')->nullable()->after('protection_degree');
            $table->string('model')->nullable()->after('paint');
            $table->string('ampere')->nullable()->after('model');
            $table->unsignedInteger('poles_count')->nullable()->after('ampere');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'conductor_type',
                'cross_section',
                'cross_section_e',
                'external_body',
                'protection_degree',
                'paint',
                'model',
                'ampere',
                'poles_count',
            ]);
        });
    }
};
