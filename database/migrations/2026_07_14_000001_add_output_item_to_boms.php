<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مكتب ادارة المشروعات — Standard BOM (سلايد 6). A BOM may now describe the
 * standard recipe of a FINISHED-GOOD item (تركيبة المنتج القياسية) rather than
 * belonging to a project. So `output_item_id` is added (nullable) and
 * `project_id` becomes nullable:
 *   - standard/library BOM: output_item_id set, project_id null.
 *   - project BOM (existing): project_id set, output_item_id null.
 * Deleting a project now detaches its BOMs (nullOnDelete) instead of cascading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->foreignId('output_item_id')
                ->nullable()
                ->after('project_id')
                ->constrained('items')
                ->nullOnDelete();
            $table->index('output_item_id');
        });

        Schema::table('boms', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('boms', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Standard BOMs (project_id = null) would violate NOT NULL on rollback;
        // drop them so the column can revert cleanly.
        Schema::table('boms', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        \Illuminate\Support\Facades\DB::table('boms')->whereNull('project_id')->delete();

        Schema::table('boms', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('boms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('output_item_id');
        });
    }
};
