<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technical specifications for items, matching the factory-warehouse sheet
 * (مخزن المصنع): category, product line (PT/DT/LT), the supplier's original
 * code, material, dimensions, finish, origin, brand, electrical rating and a
 * photo. All nullable — existing items keep working without any of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('item_category_id')->nullable()->after('type')
                ->constrained('item_categories')->nullOnDelete();
            $table->string('product_line', 10)->nullable()->after('item_category_id')
                ->comment('pt, dt, lt');
            $table->string('part_number', 100)->nullable()->index()->after('sku')
                ->comment('original supplier/sheet code; not unique');
            $table->string('material', 10)->nullable()->comment('cu, al, fe');
            $table->decimal('thickness_mm', 10, 3)->nullable();
            $table->decimal('width_mm', 10, 2)->nullable();
            $table->string('size_code', 20)->nullable()->comment('busway size, e.g. B100, 2B200');
            $table->decimal('length_m', 10, 3)->nullable();
            $table->string('edge', 10)->nullable()->comment('rounded, flat');
            $table->string('treatment', 20)->nullable()->comment('none, painted, copper_coated, tinned');
            $table->string('origin', 10)->nullable()->comment('imported, local');
            $table->string('brand', 100)->nullable();
            $table->string('usage', 100)->nullable();
            $table->string('ampere_rating', 30)->nullable();
            $table->unsignedTinyInteger('poles')->nullable();
            $table->string('size', 50)->nullable();
            $table->string('color', 30)->nullable();
            $table->string('image_path')->nullable();

            $table->index('product_line');
            $table->index('material');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_category_id');
            $table->dropIndex(['product_line']);
            $table->dropIndex(['material']);
            $table->dropIndex(['part_number']);
            $table->dropColumn([
                'product_line', 'part_number', 'material', 'thickness_mm', 'width_mm',
                'size_code', 'length_m', 'edge', 'treatment', 'origin', 'brand', 'usage',
                'ampere_rating', 'poles', 'size', 'color', 'image_path',
            ]);
        });
    }
};
