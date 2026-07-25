<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فواتير المبيعات — Sales invoices matched against delivery vouchers
 * (Financial Department سلايد 10). Sales are measured two ways: the delivery
 * voucher (goods that physically left the warehouse) and the invoice (the
 * document that proves the deal for tax). Their totals must agree, so every
 * delivery voucher carries its invoicing status: fully / partially / not
 * invoiced (samples or a personal withdrawal are legitimately un-invoiced,
 * with the reason recorded).
 *
 * A voucher may carry SEVERAL invoices — retail sales are invoiced in parts —
 * hence a child table rather than one column on the voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            // Entered by hand: the number comes from the tax invoice book /
            // e-invoicing portal outside this system.
            $table->string('invoice_number')->unique();
            $table->foreignId('delivery_voucher_id')->constrained('delivery_vouchers')->cascadeOnDelete();
            // Snapshot of the voucher's customer, for filtering and reporting.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('invoice_date');
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('delivery_voucher_id');
            $table->index('invoice_date');
        });

        Schema::table('delivery_vouchers', function (Blueprint $table) {
            // Denormalised by SalesInvoicingService::recalculate() so the
            // voucher list can sort/filter/total on invoicing state.
            $table->decimal('invoiced_value', 14, 2)->default(0)->after('total_value');
            $table->string('invoicing_status')->default('not_invoiced')->after('invoiced_value'); // App\Enums\InvoicingStatus
            $table->string('non_invoice_reason')->nullable()->after('invoicing_status');

            $table->index('invoicing_status');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_vouchers', function (Blueprint $table) {
            $table->dropIndex(['invoicing_status']);
            $table->dropColumn(['invoiced_value', 'invoicing_status', 'non_invoice_reason']);
        });

        Schema::dropIfExists('sales_invoices');
    }
};
