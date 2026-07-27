<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فوترة أذون الإضافة — Purchase invoicing status on goods receipts
 * (Financial Department سلايد 11). Purchases are measured two ways: the
 * addition voucher (goods that physically entered the store) and the supplier
 * invoice (the document that proves the deal for tax). Their totals must
 * agree, so every voucher carries its invoicing state: invoiced (with the
 * invoice number) or not invoiced — and an un-invoiced voucher is closed with
 * a written reason.
 *
 * No child table here, unlike sales: invoice_value is what
 * AdditionVoucherService::post credits the supplier with, so a parallel table
 * would create a second source of truth for the same money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addition_vouchers', function (Blueprint $table) {
            $table->date('invoice_date')->nullable()->after('invoice_number');
            // Σ (quantity × unit_cost) of the lines — the value that actually
            // entered the store. Stored (not derived) so the list can sort,
            // filter and total it against the invoice value in SQL.
            $table->decimal('received_value', 14, 2)->default(0)->after('invoice_value');
            $table->string('invoicing_status')->default('not_invoiced')->after('received_value'); // App\Enums\PurchaseInvoicingStatus
            $table->string('closure_reason')->nullable()->after('invoicing_status');
            $table->timestamp('closed_at')->nullable()->after('closure_reason');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();

            $table->index('invoicing_status');
        });

        // Backfill: existing vouchers keep telling the truth.
        $sums = DB::table('addition_voucher_lines')
            ->selectRaw('addition_voucher_id, SUM(quantity * unit_cost) as total')
            ->groupBy('addition_voucher_id')
            ->pluck('total', 'addition_voucher_id');

        foreach ($sums as $voucherId => $total) {
            DB::table('addition_vouchers')
                ->where('id', $voucherId)
                ->update(['received_value' => round((float) $total, 2)]);
        }

        DB::table('addition_vouchers')
            ->whereNotNull('invoice_number')
            ->where('invoice_number', '<>', '')
            ->update(['invoicing_status' => 'invoiced']);
    }

    public function down(): void
    {
        Schema::table('addition_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropIndex(['invoicing_status']);
            $table->dropColumn([
                'invoice_date',
                'received_value',
                'invoicing_status',
                'closure_reason',
                'closed_at',
            ]);
        });
    }
};
