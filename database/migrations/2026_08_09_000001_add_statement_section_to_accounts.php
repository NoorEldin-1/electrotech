<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند القوائم المالية — the presentation axis the four financial statements
 * (ماليات.pptx) are built on.
 *
 * `statement_section` is nullable on purpose: an existing chart keeps working
 * untouched, and App\Enums\StatementSection::defaultForType() supplies a safe
 * fallback so no account ever disappears from a statement just because nobody
 * has classified it yet.
 *
 * `contra_of_account_id` links an accumulated-depreciation account to the
 * fixed asset it depreciates, so the balance sheet can print the three columns
 * the client's own sheet uses (سلايد 6): التكلفة / مجمع الإهلاك / الصافى.
 *
 * `party_control` marks a control account whose single pooled balance must be
 * split by party balance sign on the balance sheet (سلايد 7): debit customers
 * are an asset, credit customers (دفعات مقدمة) a current liability — and the
 * same for suppliers. The split can only come from the party sub-ledger, so
 * the account has to say which sub-ledger it controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('statement_section')
                ->nullable()
                ->after('nature')
                ->comment('App\Enums\StatementSection — where the account sits in the financial statements');

            // For مجمع الإهلاك: the fixed-asset account it is deducted from.
            $table->foreignId('contra_of_account_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('accounts')
                ->nullOnDelete();

            $table->string('party_control')
                ->nullable()
                ->after('contra_of_account_id')
                ->comment('customer | supplier — split this control account by party balance sign (سلايد 7)');

            $table->index('statement_section');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['contra_of_account_id']);
            $table->dropIndex(['statement_section']);
            $table->dropColumn(['statement_section', 'contra_of_account_id', 'party_control']);
        });
    }
};
