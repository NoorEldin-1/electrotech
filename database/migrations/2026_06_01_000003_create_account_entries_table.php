<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher-driven party ledger (كشف حساب). Each posted addition voucher writes
 * a credit against its supplier; each active delivery voucher writes a debit
 * against its customer. A party's statement is just its entries ordered by
 * date with a running balance — no full chart-of-accounts required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('party'); // Supplier or Customer
            $table->date('entry_date');
            $table->string('direction'); // debit | credit
            $table->decimal('amount', 14, 2); // signed; SUM = running balance
            $table->nullableMorphs('reference'); // AdditionVoucher / DeliveryVoucher
            $table->string('operation_name')->nullable()->comment('Project / operation the entry belongs to');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['party_type', 'party_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_entries');
    }
};
