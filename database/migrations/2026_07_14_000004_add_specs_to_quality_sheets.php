<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ورقة الجودة — استكمال بيانات العملية (سلايد 3). حقول إضافية مطلوبة (مساحة
 * المقطع E / الجسم الخارجى / درجة الحماية / الدهان / الطراز / الأمبير)، وحذف
 * «نوع التوصيل». هذه الحقول لقطة تُنسَخ من أمر التصنيع عند إنشاء الورقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_sheets', function (Blueprint $table) {
            $table->string('cross_section_e')->nullable()->after('cross_section');
            $table->string('external_body')->nullable()->after('cross_section_e');
            $table->string('protection_degree')->nullable()->after('external_body');
            $table->string('paint')->nullable()->after('protection_degree');
            $table->string('model')->nullable()->after('paint');
            $table->string('ampere')->nullable()->after('model');
        });

        Schema::table('quality_sheets', function (Blueprint $table) {
            $table->dropColumn('connection_type');
        });
    }

    public function down(): void
    {
        Schema::table('quality_sheets', function (Blueprint $table) {
            $table->string('connection_type')->nullable()->after('conductor_type');
        });

        Schema::table('quality_sheets', function (Blueprint $table) {
            $table->dropColumn([
                'cross_section_e',
                'external_body',
                'protection_degree',
                'paint',
                'model',
                'ampere',
            ]);
        });
    }
};
