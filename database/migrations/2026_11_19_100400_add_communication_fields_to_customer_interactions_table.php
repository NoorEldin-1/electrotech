<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التواصل مع العميل (Final_Modifications_Plan slide 1.1) — the customer
 * communication block on the operation file reuses `customer_interactions`.
 * A call / meeting / message needs a one-line summary (the existing `notes`
 * column stays the free-text details) and the date of the next follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_interactions', function (Blueprint $table) {
            $table->string('summary')->nullable()->after('notes');
            $table->dateTime('next_follow_up_at')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('customer_interactions', function (Blueprint $table) {
            $table->dropColumn(['summary', 'next_follow_up_at']);
        });
    }
};
