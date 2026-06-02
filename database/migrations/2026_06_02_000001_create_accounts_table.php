<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شجرة الحسابات — Chart of Accounts (سلايد 5). The named general-ledger
 * accounts (treasury, banks per currency, expenses, revenues, control
 * accounts…). Each account has a natural balance side (nature) so journal
 * lines post to it according to its debit/credit nature, and an opening
 * balance (رصيد أول المدة) used as the starting point of its ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique()->comment('Optional account code for ordering / posting');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('type'); // App\Enums\AccountType
            $table->string('nature'); // App\Enums\AccountDirection — natural balance side
            $table->string('currency', 3)->default('EGP');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('opening_balance', 14, 2)->default(0)->comment('رصيد أول المدة (signed by nature)');
            $table->date('opening_balance_date')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
