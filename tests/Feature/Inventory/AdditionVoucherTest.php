<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AccountDirection;
use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\AccountEntry;
use App\Models\AdditionVoucher;
use App\Models\AdditionVoucherLine;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdditionVoucherService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdditionVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function makeVoucher(array $attrs = []): AdditionVoucher
    {
        $supplier = Supplier::factory()->create();
        $voucher = AdditionVoucher::factory()->create(array_merge([
            'supplier_id' => $supplier->id,
            'invoice_value' => 500,
            'status' => VoucherStatus::Draft,
        ], $attrs));

        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        AdditionVoucherLine::create([
            'addition_voucher_id' => $voucher->id,
            'item_id' => $item->id,
            'quantity' => 10,
            'unit_cost' => 25,
        ]);

        return $voucher->fresh('lines');
    }

    public function test_posting_adds_stock_to_raw_materials_and_credits_supplier(): void
    {
        $voucher = $this->makeVoucher();
        $item = $voucher->lines->first()->item;

        app(AdditionVoucherService::class)->post($voucher);

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Posted, $voucher->status);
        $this->assertNotNull($voucher->posted_at);

        // Stock landed in raw materials.
        $this->assertEquals(10, $item->fresh()->quantityIn(WarehouseType::RawMaterials));

        // Supplier credited with the invoice value.
        $entry = AccountEntry::where('party_type', $voucher->supplier->getMorphClass())
            ->where('party_id', $voucher->supplier_id)
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame(AccountDirection::Credit, $entry->direction);
        $this->assertEquals(500, (float) $entry->amount);
        $this->assertEquals(500, $voucher->supplier->fresh()->balance);
    }

    public function test_cannot_post_twice(): void
    {
        $voucher = $this->makeVoucher();
        $service = app(AdditionVoucherService::class);
        $service->post($voucher);

        $this->expectException(\RuntimeException::class);
        $service->post($voucher->fresh());
    }

    public function test_posting_without_lines_throws(): void
    {
        $supplier = Supplier::factory()->create();
        $voucher = AdditionVoucher::factory()->create(['supplier_id' => $supplier->id]);

        $this->expectException(\RuntimeException::class);
        app(AdditionVoucherService::class)->post($voucher);
    }

    public function test_falls_back_to_line_value_when_no_invoice_value(): void
    {
        $voucher = $this->makeVoucher(['invoice_value' => 0]);

        app(AdditionVoucherService::class)->post($voucher);

        // 10 * 25 = 250
        $this->assertEquals(250, $voucher->supplier->fresh()->balance);
    }

    public function test_rbac_warehouse_creates_procurement_posts(): void
    {
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');
        $this->assertTrue($warehouse->can('addition_vouchers.create'));
        $this->assertFalse($warehouse->can('addition_vouchers.post'));

        $procurement = User::factory()->create();
        $procurement->assignRole('Procurement');
        $this->assertTrue($procurement->can('addition_vouchers.post'));
    }
}
