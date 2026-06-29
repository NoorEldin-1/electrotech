<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scrap variant linkage on items (الفضلات بكود مختلف). A raw material can have
 * a dedicated scrap variant item — a real stock item with its own SKU
 * (`<sku>-SCRAP`) that holds returned, unconsumed material. The variant is
 * flagged `is_scrap` and points back to its source via `scrap_source_item_id`,
 * so the source item's card can surface "كام شريط وكام فضلات" together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_scrap')->default(false)->index()->after('type');
            $table->foreignId('scrap_source_item_id')
                ->nullable()
                ->after('is_scrap')
                ->constrained('items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scrap_source_item_id');
            $table->dropColumn('is_scrap');
        });
    }
};
