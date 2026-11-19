<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source reference on a journal entry (Final_Modifications Task 7, constraint
 * G8: every auto-journal is idempotent per source document).
 *
 * The inventory flows (addition, issue, return, depreciation, manufacturing
 * finish) now write their own balanced entry when the document is posted.
 * `source_type` / `source_id` say WHICH document an entry was written for and
 * `source_event` says WHAT it did for it ('post', 'finish', or
 * 'reversal:<event>' for the entry that undoes it).
 *
 * The three columns are UNIQUE together, so posting the same document twice —
 * a double click, a retried request — can never book it twice, whatever the
 * application code does. Manual entries leave all three NULL, and NULLs never
 * collide in a unique index, so the keyboard-entered books are untouched.
 *
 * No backfill (ruling R2): documents posted before this deploy keep no entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source_type', 100)->nullable()->after('document_type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_event', 60)->nullable()->after('source_id');

            $table->unique(['source_type', 'source_id', 'source_event'], 'journal_entries_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_source_unique');
            $table->dropColumn(['source_type', 'source_id', 'source_event']);
        });
    }
};
