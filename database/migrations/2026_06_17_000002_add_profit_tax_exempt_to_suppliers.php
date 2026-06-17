<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing modifications — slide 3: some suppliers are exempt from the 1%
 * commercial/industrial profits withholding by law. Flagged here; a proof
 * document is attached via the supplier attachments (slide 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('profit_tax_exempt')->default(false)->after('tax_number');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('profit_tax_exempt');
        });
    }
};
