<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promote the party-ledger `operation_name` free-text tag to a real
 * project/operation foreign key. Kept alongside `operation_name` for backward
 * compatibility (existing rows keep their string). Nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_entries', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('operation_name')
                ->constrained('projects')
                ->nullOnDelete();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('account_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
