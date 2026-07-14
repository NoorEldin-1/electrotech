<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنود الاختبار — إعادة هيكلة (سلايد 4):
 *  - (الجودة الظاهرية / التجميع / تربيط الجانب الأرضى PE–FE) → علامة صح boolean.
 *  - «المقاس المطلوب» يبقى نصياً كما هو.
 *  - بقية خانات الاختبار الكهربى: قراءتان لكل خانة (_r1 / _r2).
 */
return new class extends Migration
{
    /** The five electrical test columns that split into two readings each. */
    private array $tests = [
        'test_pe_l123n',
        'test_fe_l123n',
        'test_n_l12l3',
        'test_l1_l2l3',
        'test_l2_l3',
    ];

    public function up(): void
    {
        // visual_quality / assembly are strings today → drop & re-add as booleans.
        Schema::table('quality_sheet_lines', function (Blueprint $table) {
            $table->dropColumn(['visual_quality', 'assembly', ...$this->tests]);
        });

        Schema::table('quality_sheet_lines', function (Blueprint $table) {
            $table->boolean('visual_quality')->default(false)->after('piece_number');
            $table->boolean('assembly')->default(false)->after('visual_quality');
            $table->boolean('earth_bond_pe_fe')->default(false)->after('assembly');

            foreach ($this->tests as $test) {
                $table->string($test . '_r1')->nullable();
                $table->string($test . '_r2')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quality_sheet_lines', function (Blueprint $table) {
            $columns = ['visual_quality', 'assembly', 'earth_bond_pe_fe'];
            foreach ($this->tests as $test) {
                $columns[] = $test . '_r1';
                $columns[] = $test . '_r2';
            }
            $table->dropColumn($columns);
        });

        Schema::table('quality_sheet_lines', function (Blueprint $table) {
            $table->string('visual_quality')->nullable()->after('piece_number');
            $table->string('assembly')->nullable()->after('visual_quality');
            foreach ($this->tests as $test) {
                $table->string($test)->nullable();
            }
        });
    }
};
