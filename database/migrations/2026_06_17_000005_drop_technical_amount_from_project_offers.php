<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the standalone "technical amount (separate engineering offer)" column
 * from project_offers. The figure is no longer captured anywhere in the sales
 * flow — the offer total is driven entirely by the BOQ line items, VAT and
 * installation. Reversible: down() re-adds the nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            if (Schema::hasColumn('project_offers', 'technical_amount')) {
                $table->dropColumn('technical_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('project_offers', 'technical_amount')) {
                $table->decimal('technical_amount', 15, 2)->nullable()->after('financial_amount');
            }
        });
    }
};
