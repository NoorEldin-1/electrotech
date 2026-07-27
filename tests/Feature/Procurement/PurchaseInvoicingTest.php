<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\ItemType;
use App\Enums\PurchaseInvoicingStatus;
use App\Models\AccountEntry;
use App\Models\AdditionVoucher;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdditionVoucherService;
use App\Services\PurchaseInvoicingService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فوترة أذون الإضافة (Financial Department سلايد 11) — a goods receipt is
 * either invoiced (with the supplier's invoice number) or awaiting its
 * invoice, and an un-invoiced receipt is closed with a written reason. The
 * invoice value must match the value that entered the store, and correcting
 * it after posting fixes the supplier's ledger entry instead of doubling it.
 */
class PurchaseInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoicingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->service = app(PurchaseInvoicingService::class);
    }

    /**
     * A draft voucher with one line worth $lineValue and no invoice yet.
     */
    private function voucherWithLine(float $quantity = 10, float $unitCost = 25): AdditionVoucher
    {
        $voucher = AdditionVoucher::factory()->uninvoiced()->create([
            'supplier_id' => Supplier::factory()->create()->id,
        ]);

        $voucher->lines()->create([
            'item_id' => Item::factory()->create(['type' => ItemType::RawMaterial])->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ]);

        return $voucher->refresh();
    }

    private function invoiceData(string $number, float $value): array
    {
        return [
            'invoice_number' => $number,
            'invoice_date' => now()->toDateString(),
            'invoice_value' => $value,
        ];
    }

    public function test_a_receipt_without_an_invoice_is_not_invoiced(): void
    {
        $voucher = $this->voucherWithLine();

        $this->assertSame(PurchaseInvoicingStatus::NotInvoiced, $voucher->invoicing_status);
        $this->assertFalse($voucher->isInvoiced());
    }

    public function test_lines_drive_the_received_value(): void
    {
        $voucher = $this->voucherWithLine(quantity: 10, unitCost: 25);

        $this->assertSame(250.0, (float) $voucher->received_value);

        // Editing a line keeps the stored value true.
        $voucher->lines()->first()->update(['quantity' => 12]);

        $this->assertSame(300.0, (float) $voucher->refresh()->received_value);

        $voucher->lines()->first()->delete();

        $this->assertSame(0.0, (float) $voucher->refresh()->received_value);
    }

    public function test_recording_the_supplier_invoice_marks_the_receipt_invoiced(): void
    {
        $voucher = $this->voucherWithLine();

        $this->service->recordInvoice($voucher, $this->invoiceData('SINV-1', 250));

        $voucher->refresh();
        $this->assertSame(PurchaseInvoicingStatus::Invoiced, $voucher->invoicing_status);
        $this->assertSame('SINV-1', $voucher->invoice_number);
        $this->assertSame(250.0, (float) $voucher->invoice_value);
        $this->assertNull($voucher->invoiceValueMismatch());
    }

    public function test_an_invoice_that_disagrees_with_the_goods_is_flagged(): void
    {
        $voucher = $this->voucherWithLine(quantity: 10, unitCost: 25);

        $this->service->recordInvoice($voucher, $this->invoiceData('SINV-2', 300));

        // The file's rule: purchase invoices must equal addition vouchers.
        $this->assertSame(50.0, $voucher->refresh()->invoiceValueMismatch());
    }

    public function test_a_negative_invoice_value_is_rejected(): void
    {
        $voucher = $this->voucherWithLine();

        $this->expectException(\RuntimeException::class);
        $this->service->recordInvoice($voucher, $this->invoiceData('SINV-3', -10));
    }

    public function test_a_posted_receipt_can_be_closed_without_an_invoice_with_a_reason(): void
    {
        $user = User::factory()->create();
        $voucher = $this->voucherWithLine();
        $voucher->update(['status' => \App\Enums\VoucherStatus::Posted, 'posted_at' => now()]);

        $this->service->closeWithoutInvoice($voucher, 'Free warranty replacement', $user);

        $voucher->refresh();
        $this->assertSame(PurchaseInvoicingStatus::ClosedUninvoiced, $voucher->invoicing_status);
        $this->assertSame('Free warranty replacement', $voucher->closure_reason);
        $this->assertSame($user->id, $voucher->closed_by);
        $this->assertNotNull($voucher->closed_at);
    }

    public function test_a_draft_receipt_cannot_be_closed(): void
    {
        // A draft is still editable and deletable — closing it means nothing.
        $voucher = $this->voucherWithLine();

        $this->expectException(\RuntimeException::class);
        $this->service->closeWithoutInvoice($voucher, 'No invoice expected');
    }

    public function test_an_invoiced_receipt_cannot_be_closed(): void
    {
        $voucher = $this->voucherWithLine();
        $voucher->update(['status' => \App\Enums\VoucherStatus::Posted, 'posted_at' => now()]);
        $this->service->recordInvoice($voucher, $this->invoiceData('SINV-4', 250));

        $this->expectException(\RuntimeException::class);
        $this->service->closeWithoutInvoice($voucher->refresh(), 'Changed my mind');
    }

    public function test_closing_without_a_reason_is_rejected(): void
    {
        $voucher = $this->voucherWithLine();
        $voucher->update(['status' => \App\Enums\VoucherStatus::Posted, 'posted_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->service->closeWithoutInvoice($voucher, '   ');
    }

    public function test_an_arriving_invoice_overrides_a_closure(): void
    {
        $voucher = $this->voucherWithLine();
        $voucher->update(['status' => \App\Enums\VoucherStatus::Posted, 'posted_at' => now()]);
        $this->service->closeWithoutInvoice($voucher, 'No invoice expected');

        $this->service->recordInvoice($voucher->refresh(), $this->invoiceData('SINV-5', 250));

        $voucher->refresh();
        $this->assertSame(PurchaseInvoicingStatus::Invoiced, $voucher->invoicing_status);
        $this->assertNull($voucher->closure_reason);
        $this->assertNull($voucher->closed_at);
    }

    public function test_reopening_a_closed_receipt_puts_it_back_in_the_waiting_list(): void
    {
        $voucher = $this->voucherWithLine();
        $voucher->update(['status' => \App\Enums\VoucherStatus::Posted, 'posted_at' => now()]);
        $this->service->closeWithoutInvoice($voucher, 'No invoice expected');

        $this->service->reopen($voucher->refresh());

        $voucher->refresh();
        $this->assertSame(PurchaseInvoicingStatus::NotInvoiced, $voucher->invoicing_status);
        $this->assertNull($voucher->closure_reason);
    }

    public function test_invoicing_after_posting_corrects_the_supplier_entry_instead_of_doubling_it(): void
    {
        $this->actingAs(User::factory()->create());

        $voucher = $this->voucherWithLine(quantity: 10, unitCost: 25);
        // Posted before the invoice arrived: the supplier was credited with
        // the estimated stock value (250).
        app(AdditionVoucherService::class)->post($voucher);

        $this->assertSame(1, AccountEntry::count());
        $this->assertSame(250.0, (float) AccountEntry::first()->amount);

        // The real invoice says 275.
        $this->service->recordInvoice($voucher->refresh(), $this->invoiceData('SINV-6', 275));

        $this->assertSame(1, AccountEntry::count(), 'A second entry would double the supplier balance.');
        $this->assertSame(275.0, (float) AccountEntry::first()->amount);
        $this->assertSame('Invoice #SINV-6', AccountEntry::first()->notes);
    }

    public function test_invoicing_an_unposted_receipt_writes_no_account_entry(): void
    {
        $voucher = $this->voucherWithLine();

        $this->service->recordInvoice($voucher, $this->invoiceData('SINV-7', 250));

        // Nothing was posted to the ledger yet — posting later will use the
        // invoice value that is now on the voucher.
        $this->assertSame(0, AccountEntry::count());
    }

    public function test_rbac_matrix_for_purchase_invoicing(): void
    {
        $voucher = $this->voucherWithLine();

        $expectations = [
            'Finance' => true,
            'Procurement' => true,
            // Pricing is hidden from storekeepers by system spec, and the
            // invoice value is money.
            'Warehouse_Manager' => false,
            'Sales' => false,
            'General_Manager' => false,
        ];

        foreach ($expectations as $role => $canInvoice) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertSame($canInvoice, $user->can('invoice', $voucher), "{$role} invoice");
        }
    }

    public function test_translation_keys_exist_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ([
                'resources.addition_vouchers.fields.invoice_date',
                'resources.addition_vouchers.fields.closure_reason',
                'resources.addition_vouchers.fields.closure_reason_hint',
                'resources.addition_vouchers.columns.invoicing_status',
                'resources.addition_vouchers.columns.received_value',
                'resources.addition_vouchers.columns.total_received',
                'resources.addition_vouchers.columns.total_invoiced',
                'resources.addition_vouchers.filters.value_mismatch',
                'resources.addition_vouchers.actions.record_invoice',
                'resources.addition_vouchers.actions.close_without_invoice',
                'resources.addition_vouchers.actions.reopen_invoicing',
                'resources.addition_vouchers.notifications.invoice_recorded',
                'resources.addition_vouchers.notifications.closed_uninvoiced',
                'resources.enums.purchase_invoicing_status.not_invoiced',
                'resources.enums.purchase_invoicing_status.invoiced',
                'resources.enums.purchase_invoicing_status.closed_uninvoiced',
                'resources.roles.permissions.addition_vouchers.invoice',
                'errors.purchase_invoice.not_posted',
                'errors.purchase_invoice.already_invoiced',
                'errors.purchase_invoice.reason_required',
                'errors.purchase_invoice.invalid_value',
            ] as $key) {
                $this->assertNotSame($key, __($key), "Missing {$key} in {$locale}");
            }
        }

        app()->setLocale('en');
    }

    public function test_enum_colors_are_semantic(): void
    {
        $this->assertSame('danger', PurchaseInvoicingStatus::NotInvoiced->getColor());
        $this->assertSame('success', PurchaseInvoicingStatus::Invoiced->getColor());
        $this->assertSame('gray', PurchaseInvoicingStatus::ClosedUninvoiced->getColor());
    }
}
