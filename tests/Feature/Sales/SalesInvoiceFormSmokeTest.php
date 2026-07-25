<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\DeliveryVoucherStatus;
use App\Filament\Resources\DeliveryVoucherResource\Pages\ListDeliveryVouchers;
use App\Filament\Resources\SalesInvoiceResource\Pages\CreateSalesInvoice;
use App\Filament\Resources\SalesInvoiceResource\Pages\EditSalesInvoice;
use App\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\User;
use App\Services\SalesInvoicingService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The sales-invoice screens and the delivery-voucher list with its new
 * invoicing columns / summarizers / actions render without error (سلايد 10).
 */
class SalesInvoiceFormSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    private function activeVoucher(): DeliveryVoucher
    {
        return DeliveryVoucher::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'status' => DeliveryVoucherStatus::Active,
            'total_value' => 1000,
        ]);
    }

    public function test_sales_invoice_create_form_renders(): void
    {
        $this->activeVoucher();

        Livewire::test(CreateSalesInvoice::class)->assertOk();
    }

    public function test_sales_invoice_list_and_edit_render(): void
    {
        $voucher = $this->activeVoucher();
        $invoice = app(SalesInvoicingService::class)->record($voucher, [
            'invoice_number' => 'INV-SMOKE-1',
            'invoice_date' => now()->toDateString(),
            'amount' => 400,
        ], auth()->user());

        Livewire::test(ListSalesInvoices::class)->assertOk();
        Livewire::test(EditSalesInvoice::class, ['record' => $invoice->getRouteKey()])->assertOk();
    }

    public function test_delivery_voucher_list_with_invoicing_columns_renders(): void
    {
        $this->activeVoucher();

        Livewire::test(ListDeliveryVouchers::class)
            ->assertOk()
            ->assertCanSeeTableRecords(DeliveryVoucher::all());
    }

    public function test_row_action_dropdown_items_carry_a_stable_livewire_key(): void
    {
        // Without a key, Livewire morphs dropdown items by position: when an
        // action disappears (approval signed, voucher fully invoiced) the
        // loading state lands on the neighbouring action instead.
        $this->activeVoucher();

        $html = Livewire::test(ListDeliveryVouchers::class)->html();

        preg_match_all('/wire:click="[^"]*mountTableAction[^"]*"/', $html, $clicks);
        preg_match_all('/wire:key="fi-ac-grouped-[a-f0-9]{32}"/', $html, $keys);

        $this->assertNotEmpty($clicks[0], 'The row dropdown should render table actions.');
        $this->assertCount(count($clicks[0]), $keys[0], 'Every grouped action needs its own key.');
        $this->assertCount(count($keys[0]), array_unique($keys[0]), 'Keys must be unique per action.');
    }

    public function test_record_invoice_action_creates_the_invoice_from_the_voucher_list(): void
    {
        $voucher = $this->activeVoucher();

        Livewire::test(ListDeliveryVouchers::class)
            ->callTableAction('record_invoice', $voucher, [
                'invoice_number' => 'INV-SMOKE-2',
                'invoice_date' => now()->toDateString(),
                'amount' => 1000,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($voucher->refresh()->isFullyInvoiced());
    }
}
