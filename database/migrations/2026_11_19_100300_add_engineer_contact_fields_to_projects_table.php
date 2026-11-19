<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final_Modifications Task 3 — engineer contact block next to `engineer_name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('engineer_phone', 50)->nullable()->after('engineer_name');
            $table->string('engineer_email')->nullable()->after('engineer_phone');
            $table->string('engineer_office')->nullable()->after('engineer_email');
            $table->string('engineer_site')->nullable()->after('engineer_office');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['engineer_phone', 'engineer_email', 'engineer_office', 'engineer_site']);
        });
    }
};
