<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رقم القيد — a plain running serial for journal entries (قائمة المواد.pptx
 * سلايد 2). The client's daybook shows two distinct numbers per row: a simple
 * sequential entry number (64, 65, 66…) and the paper document number (3140,
 * 3141… / 160 for settlements). The existing `entry_number` (PV-202607-0001)
 * stays as the internal identifier; this column carries the number the
 * accountant actually reads and quotes.
 *
 * The sequence is global and never resets — matching the sample, where the
 * serial keeps climbing across document types within the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedInteger('entry_serial')->nullable()->after('entry_number');
        });

        // Backfill in creation order so existing books keep a stable reading
        // order. Done row by row to stay portable across MySQL and SQLite.
        $serial = 0;
        DB::table('journal_entries')->orderBy('id')->select('id')->each(function ($row) use (&$serial) {
            DB::table('journal_entries')->where('id', $row->id)->update(['entry_serial' => ++$serial]);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique('entry_serial');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['entry_serial']);
            $table->dropColumn('entry_serial');
        });
    }
};
