<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalise attachment categories to the AttachmentCategory enum and
 * index (project_id, category) for the per-category file pickers in
 * the project form.
 *
 * Pre-existing free-text values that match older labels (drawings /
 * specs / vendor_list) are mapped onto the new canonical values
 * (drowing / speces / vendor_list) — the spellings in Slide 2 of
 * Sales_Department.md are kept as the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('attachments')->where('category', 'drawings')->update(['category' => 'drowing']);
        DB::table('attachments')->where('category', 'specs')->update(['category' => 'speces']);

        Schema::table('attachments', function (Blueprint $table) {
            $table->index(['project_id', 'category'], 'attachments_project_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('attachments_project_category_idx');
        });

        DB::table('attachments')->where('category', 'drowing')->update(['category' => 'drawings']);
        DB::table('attachments')->where('category', 'speces')->update(['category' => 'specs']);
    }
};
