<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\ItemType;
use App\Enums\PurchaseInvoicingStatus;
use App\Enums\VoucherStatus;
use App\Filament\Resources\AdditionVoucherResource\Pages\ListAdditionVouchers;
use App\Models\AdditionVoucher;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseInvoicingService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The addition-voucher list with its new invoicing columns / summarizers /
 * filters / actions renders and works from the screen (سلايد 11).
 */
class PurchaseInvoicingFormSmokeTest extends TestCase
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

    private function voucher(bool $posted = false): AdditionVoucher
    {
        $voucher = AdditionVoucher::factory()->uninvoiced()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'status' => $posted ? VoucherStatus::Posted : VoucherStatus::Draft,
            'posted_at' => $posted ? now() : null,
        ]);

        $voucher->lines()->create([
            'item_id' => Item::factory()->create(['type' => ItemType::RawMaterial])->id,
            'quantity' => 10,
            'unit_cost' => 25,
        ]);

        return $voucher->refresh();
    }

    public function test_the_voucher_list_with_invoicing_columns_renders(): void
    {
        $this->voucher();

        Livewire::test(ListAdditionVouchers::class)
            ->assertOk()
            ->assertCanSeeTableRecords(AdditionVoucher::all());
    }

    public function test_recording_the_invoice_from_the_list_works(): void
    {
        $voucher = $this->voucher();

        Livewire::test(ListAdditionVouchers::class)
            ->callTableAction('record_invoice', $voucher, [
                'invoice_number' => 'SINV-SMOKE-1',
                'invoice_date' => now()->toDateString(),
                'invoice_value' => 250,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PurchaseInvoicingStatus::Invoiced, $voucher->refresh()->invoicing_status);
    }

    public function test_closing_without_an_invoice_from_the_list_works(): void
    {
        $voucher = $this->voucher(posted: true);

        Livewire::test(ListAdditionVouchers::class)
            ->callTableAction('close_without_invoice', $voucher, [
                'closure_reason' => 'Free warranty replacement',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PurchaseInvoicingStatus::ClosedUninvoiced, $voucher->refresh()->invoicing_status);
    }

    public function test_the_value_mismatch_filter_isolates_disagreeing_invoices(): void
    {
        $matching = $this->voucher();
        $mismatched = $this->voucher();

        $service = app(PurchaseInvoicingService::class);
        $service->recordInvoice($matching, ['invoice_number' => 'OK-1', 'invoice_value' => 250]);
        $service->recordInvoice($mismatched, ['invoice_number' => 'BAD-1', 'invoice_value' => 300]);

        Livewire::test(ListAdditionVouchers::class)
            ->filterTable('value_mismatch')
            ->assertCanSeeTableRecords([$mismatched->refresh()])
            ->assertCanNotSeeTableRecords([$matching->refresh()]);
    }

    public function test_row_action_dropdown_items_carry_a_stable_livewire_key(): void
    {
        // Without a key, Livewire morphs dropdown items by position: when an
        // action disappears (voucher posted, invoice recorded) the loading
        // state lands on the neighbouring action instead.
        $this->voucher();

        $html = Livewire::test(ListAdditionVouchers::class)->html();

        preg_match_all('/wire:key="fi-ac-grouped-[a-f0-9]{32}"/', $html, $keys);

        // Post, Record Invoice, View and Edit are all available on a draft
        // voucher — four grouped items, four distinct keys.
        $this->assertNotEmpty($keys[0], 'The row dropdown should render grouped actions.');
        $this->assertCount(count($keys[0]), array_unique($keys[0]), 'Keys must be unique per action.');
    }
}
