<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قيود اليومية — Journal Entries (سلايد 2). A double-entry journal: each entry
 * has a document number/type, a date and a description (البيان), and a set of
 * lines each posting a debit or credit against an account. SUM(debit) must
 * equal SUM(credit) before the entry can be posted; posted lines are what the
 * general ledger and trial balance read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique();
            $table->string('document_number')->nullable();
            $table->string('document_type'); // App\Enums\DocumentType
            $table->date('entry_date');
            $table->string('description')->nullable(); // البيان
            $table->string('status')->default('draft')->index(); // App\Enums\JournalStatus
            $table->string('currency', 3)->default('EGP');
            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('total_credit', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index('entry_date');
            $table->index('document_type');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('direction'); // App\Enums\AccountDirection (debit | credit)
            $table->decimal('amount', 14, 2); // always positive; direction carries the sign
            $table->string('line_notes')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};
