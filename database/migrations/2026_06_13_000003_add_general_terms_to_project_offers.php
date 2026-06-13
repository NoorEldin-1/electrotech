<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split the single terms block into special vs general — Slides 3, 8 & 9.
 *
 * `terms` (existing) becomes the per-offer SPECIAL terms printed directly
 * behind the tables. `general_terms` (new) holds the standard, mostly-fixed
 * terms that are pre-filled from a template when the offer is created and then
 * tweaked (add / remove a point) by Sales. Printed as a numbered list after the
 * special terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            $table->text('general_terms')->nullable()->after('header_note');
        });
    }

    public function down(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            $table->dropColumn('general_terms');
        });
    }
};
