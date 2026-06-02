<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost-center dimension on GL lines (الإدارة العامة — العملية = مركز تكلفة).
 *
 * A journal entry line can optionally be tagged to a project/operation so the
 * Operation Cost Center can aggregate operating/installation/general expenses
 * onto the operation. Nullable — bank-to-bank and other non-operation lines
 * stay untagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('account_id')
                ->constrained('projects')
                ->nullOnDelete();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
