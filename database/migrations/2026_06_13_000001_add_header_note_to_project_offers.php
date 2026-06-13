<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Header note above the BOQ tables — Slides 5 & 11 of the modifications round.
 *
 * Every offer opens with a short intro line ("With reference to your request …")
 * and the mandatory licence statement "The busway system is manufactured under
 * license from DKC – Italy." which Sales said MUST appear before any financial
 * offer. It is per-offer (editable) but pre-filled from a default so it is
 * never forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            $table->text('header_note')->nullable()->after('terms');
        });
    }

    public function down(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            $table->dropColumn('header_note');
        });
    }
};
