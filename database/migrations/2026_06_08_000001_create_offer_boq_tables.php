<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ-style offers — Slides 2, 7 & 8 of the Sales modifications.
 *
 * The offer is no longer a single financial figure: it carries one or more
 * tables ("Bi-Metal Offer", "Copper Offer", …), each a list of priced line
 * items (description / unit / qty / unit price / line total), plus VAT and a
 * free-text terms block. Totals roll up items → group subtotal → offer
 * subtotal → tax → grand total, and the grand total is mirrored back onto
 * project_offers.financial_amount so the existing Tender "last offer" columns
 * keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_offers', function (Blueprint $table) {
            $table->string('quotation_number')->nullable()->after('version');
            $table->string('currency', 8)->default('EGP')->after('quotation_number');
            $table->decimal('vat_percentage', 5, 2)->default(14)->after('technical_amount');
            $table->boolean('show_vat')->default(true)->after('vat_percentage');
            $table->decimal('subtotal', 15, 2)->default(0)->after('show_vat');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('subtotal');
            $table->decimal('grand_total', 15, 2)->default(0)->after('tax_amount');
            $table->text('terms')->nullable()->after('notes');
        });

        Schema::create('offer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_offer_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('conductor_type')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('project_offer_id');
        });

        Schema::create('offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_group_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('offer_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_items');
        Schema::dropIfExists('offer_groups');

        Schema::table('project_offers', function (Blueprint $table) {
            $table->dropColumn([
                'quotation_number',
                'currency',
                'vat_percentage',
                'show_vat',
                'subtotal',
                'tax_amount',
                'grand_total',
                'terms',
            ]);
        });
    }
};
