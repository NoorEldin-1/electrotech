<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\InvoicingStatus;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\SalesInvoicingService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فوترة أذون التسليم (Financial Department سلايد 10) — a delivery voucher is
 * fully / partially / not invoiced, invoices may not exceed the delivered
 * value, and an invoice never touches the accounts.
 */
class SalesInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoicingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->service = app(SalesInvoicingService::class);
    }

    private function activeVoucher(float $total = 1000): DeliveryVoucher
    {
        return DeliveryVoucher::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'status' => DeliveryVoucherStatus::Active,
            'total_value' => $total,
        ]);
    }

    private function invoiceData(string $number, float $amount): array
    {
        return [
            'invoice_number' => $number,
            'invoice_date' => now()->toDateString(),
            'amount' => $amount,
        ];
    }

    public function test_a_delivered_voucher_starts_not_invoiced(): void
    {
        $voucher = $this->activeVoucher();

        $this->assertSame(InvoicingStatus::NotInvoiced, $voucher->invoicing_status);
        $this->assertSame(0.0, (float) $voucher->invoiced_value);
    }

    public function test_partial_invoice_marks_the_voucher_partially_invoiced(): void
    {
        $voucher = $this->activeVoucher(1000);

        $this->service->record($voucher, $this->invoiceData('INV-1', 400), null);

        $voucher->refresh();
        $this->assertSame(InvoicingStatus::PartiallyInvoiced, $voucher->invoicing_status);
        $this->assertSame(400.0, (float) $voucher->invoiced_value);
        $this->assertSame(600.0, $this->service->remainingFor($voucher));
    }

    public function test_invoicing_the_remainder_marks_the_voucher_fully_invoiced(): void
    {
        $voucher = $this->activeVoucher(1000);

        // Retail-style: the same voucher invoiced in two parts.
        $this->service->record($voucher, $this->invoiceData('INV-1', 400), null);
        $this->service->record($voucher->refresh(), $this->invoiceData('INV-2', 600), null);

        $voucher->refresh();
        $this->assertSame(InvoicingStatus::FullyInvoiced, $voucher->invoicing_status);
        $this->assertSame(1000.0, (float) $voucher->invoiced_value);
        $this->assertSame(0.0, $this->service->remainingFor($voucher));
        $this->assertTrue($voucher->isFullyInvoiced());
    }

    public function test_over_invoicing_is_rejected(): void
    {
        $voucher = $this->activeVoucher(1000);
        $this->service->record($voucher, $this->invoiceData('INV-1', 900), null);

        $this->expectException(\RuntimeException::class);
        $this->service->record($voucher->refresh(), $this->invoiceData('INV-2', 200), null);
    }

    public function test_a_voucher_that_is_not_delivered_yet_cannot_be_invoiced(): void
    {
        $voucher = DeliveryVoucher::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'status' => DeliveryVoucherStatus::Draft,
            'total_value' => 500,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->record($voucher, $this->invoiceData('INV-1', 100), null);
    }

    public function test_deleting_an_invoice_rolls_the_status_back(): void
    {
        $voucher = $this->activeVoucher(1000);
        $invoice = $this->service->record($voucher, $this->invoiceData('INV-1', 1000), null);

        $this->assertSame(InvoicingStatus::FullyInvoiced, $voucher->refresh()->invoicing_status);

        $invoice->delete();

        $voucher->refresh();
        $this->assertSame(InvoicingStatus::NotInvoiced, $voucher->invoicing_status);
        $this->assertSame(0.0, (float) $voucher->invoiced_value);
    }

    public function test_editing_an_invoice_amount_recalculates_the_status(): void
    {
        $voucher = $this->activeVoucher(1000);
        $invoice = $this->service->record($voucher, $this->invoiceData('INV-1', 1000), null);

        $invoice->update(['amount' => 250]);

        $voucher->refresh();
        $this->assertSame(InvoicingStatus::PartiallyInvoiced, $voucher->invoicing_status);
        $this->assertSame(250.0, (float) $voucher->invoiced_value);
    }

    public function test_an_invoice_writes_no_account_entry(): void
    {
        // The voucher's activation already debits the customer; posting the
        // invoice as well would double the customer's balance.
        $voucher = $this->activeVoucher(1000);

        $this->service->record($voucher, $this->invoiceData('INV-1', 1000), null);

        $this->assertSame(0, AccountEntry::count());
    }

    public function test_the_invoice_snapshots_the_voucher_customer(): void
    {
        $voucher = $this->activeVoucher(500);

        $invoice = $this->service->record($voucher, $this->invoiceData('INV-1', 500), null);

        $this->assertSame($voucher->customer_id, $invoice->customer_id);
    }

    public function test_rbac_matrix_for_sales_invoices(): void
    {
        $expectations = [
            'Finance' => [true, true],
            'Sales_Manager' => [true, true],
            'Sales' => [true, false],
            'General_Manager' => [true, false],
            'Procurement' => [false, false],
        ];

        foreach ($expectations as $role => [$canView, $canCreate]) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertSame($canView, $user->can('viewAny', SalesInvoice::class), "{$role} view");
            $this->assertSame($canCreate, $user->can('create', SalesInvoice::class), "{$role} create");
        }
    }

    public function test_translation_keys_exist_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ([
                'resources.sales_invoices.label',
                'resources.sales_invoices.plural_label',
                'resources.sales_invoices.fields.delivery_voucher',
                'resources.sales_invoices.fields.remaining',
                'resources.sales_invoices.columns.invoice_number',
                'resources.sales_invoices.filters.customer',
                'resources.delivery_vouchers.columns.invoicing_status',
                'resources.delivery_vouchers.columns.invoiced_value',
                'resources.delivery_vouchers.actions.record_invoice',
                'resources.delivery_vouchers.fields.non_invoice_reason',
                'resources.enums.invoicing_status.not_invoiced',
                'resources.enums.invoicing_status.partially_invoiced',
                'resources.enums.invoicing_status.fully_invoiced',
                'errors.sales_invoice.voucher_not_active',
                'errors.sales_invoice.exceeds_voucher_value',
            ] as $key) {
                $this->assertNotSame($key, __($key), "Missing {$key} in {$locale}");
            }
        }

        app()->setLocale('en');
    }

    public function test_enum_colors_are_semantic(): void
    {
        $this->assertSame('danger', InvoicingStatus::NotInvoiced->getColor());
        $this->assertSame('warning', InvoicingStatus::PartiallyInvoiced->getColor());
        $this->assertSame('success', InvoicingStatus::FullyInvoiced->getColor());
    }
}
