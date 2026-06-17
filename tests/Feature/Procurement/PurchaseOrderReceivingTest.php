<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\ItemType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\AccountEntry;
use App\Models\AdditionVoucher;
use App\Models\Item;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdditionVoucherService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slides 1, 7 & 9: receiving flows through a single addition voucher — stock is
 * added once (no double counting), the PO is closed by automatic comparison,
 * and a voucher without a registered supplier skips the ledger.
 */
class PurchaseOrderReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function poWithLine(int $ordered = 10, float $price = 25): array
    {
        $supplier = Supplier::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => PurchaseOrderStatus::Submitted,
        ]);
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $poItem = $po->items()->create(['item_id' => $item->id, 'quantity' => $ordered, 'unit_price' => $price]);

        return [$po, $poItem, $item, $supplier];
    }

    public function test_receiving_partial_adds_stock_once_and_marks_partially_received(): void
    {
        [$po, $poItem, $item, $supplier] = $this->poWithLine(ordered: 10, price: 25);

        $voucher = app(PurchaseOrderService::class)->receiveItems($po->fresh(), [$poItem->id => 4]);

        // Stock added exactly once — no double count from a second path.
        $this->assertEquals(4, $item->fresh()->quantityIn(WarehouseType::RawMaterials));
        $this->assertSame(1, InventoryTransaction::where('item_id', $item->id)->count());

        // PO closed by comparison: 4 of 10 → partially received.
        $po->refresh();
        $this->assertEquals(4, (float) $po->items()->first()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->status);

        // A posted voucher linked back to the PO (slide 7).
        $this->assertSame($po->id, $voucher->purchase_order_id);
        $this->assertSame(VoucherStatus::Posted, $voucher->fresh()->status);

        // Supplier credited 4 × 25 = 100.
        $this->assertEquals(100, $supplier->fresh()->balance);
    }

    public function test_receiving_full_quantity_marks_received(): void
    {
        [$po, $poItem, $item] = $this->poWithLine(ordered: 10, price: 25);

        app(PurchaseOrderService::class)->receiveItems($po->fresh(), [$poItem->id => 10]);

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Received, $po->status);
        $this->assertEquals(10, $item->fresh()->quantityIn(WarehouseType::RawMaterials));
    }

    public function test_receiving_more_than_remaining_throws(): void
    {
        [$po, $poItem] = $this->poWithLine(ordered: 10);

        $this->expectException(\RuntimeException::class);
        app(PurchaseOrderService::class)->receiveItems($po->fresh(), [$poItem->id => 11]);
    }

    public function test_manual_voucher_linked_to_po_closes_it(): void
    {
        [$po, $poItem, $item, $supplier] = $this->poWithLine(ordered: 10, price: 25);

        $voucher = AdditionVoucher::create([
            'voucher_number' => AdditionVoucher::generateVoucherNumber(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'voucher_date' => now(),
            'status' => VoucherStatus::Draft,
        ]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 25]);

        app(AdditionVoucherService::class)->post($voucher);

        $po->refresh();
        $this->assertEquals(3, (float) $po->items()->first()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->status);
    }

    public function test_voucher_without_supplier_adds_stock_but_skips_ledger(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $voucher = AdditionVoucher::create([
            'voucher_number' => AdditionVoucher::generateVoucherNumber(),
            'supplier_id' => null,
            'supplier_name' => 'Cash purchase',
            'voucher_date' => now(),
            'status' => VoucherStatus::Draft,
        ]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => 5, 'unit_cost' => 10]);

        app(AdditionVoucherService::class)->post($voucher);

        $this->assertEquals(5, $item->fresh()->quantityIn(WarehouseType::RawMaterials));
        $this->assertSame(0, AccountEntry::count());
        $this->assertSame('Cash purchase', $voucher->fresh()->supplier_label);
    }
}
