<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installation surcharge as a percentage — Slides 1 & 8 of the modifications.
 *
 * Sales asked for an "installation" toggle that behaves exactly like VAT: it is
 * a percentage of the subtotal (default 10%) added on top of the price, and it
 * is shown only when relevant (some clients book installation as a contract
 * line, others don't). Off by default — installation is opt-in per offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            $table->decimal('installation_percentage', 5, 2)->default(10)->after('show_vat');
            $table->boolean('show_installation')->default(false)->after('installation_percentage');
            $table->decimal('installation_amount', 15, 2)->default(0)->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            $table->dropColumn(['installation_percentage', 'show_installation', 'installation_amount']);
        });
    }
};
