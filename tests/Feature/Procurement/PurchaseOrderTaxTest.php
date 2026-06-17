<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 3: a PO adds 14% VAT and deducts 1% commercial/industrial profits
 * withholding, unless the supplier is exempt.
 */
class PurchaseOrderTaxTest extends TestCase
{
    use RefreshDatabase;

    private function poWithItems(bool $applyProfitTax): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'apply_profit_tax' => $applyProfitTax,
            'total_amount' => 0,
        ]);

        $item = Item::factory()->create();
        $po->items()->create(['item_id' => $item->id, 'quantity' => 10, 'unit_price' => 100]); // 1000
        $po->items()->create(['item_id' => $item->id, 'quantity' => 5, 'unit_price' => 200]);  // 1000

        return $po;
    }

    public function test_total_adds_vat_and_deducts_one_percent_when_not_exempt(): void
    {
        $po = $this->poWithItems(applyProfitTax: true);

        $po->recalculateTotal();

        $this->assertEqualsWithDelta(2000.0, (float) $po->subtotal, 0.001);
        $this->assertEqualsWithDelta(280.0, (float) $po->vat_amount, 0.001);      // 14%
        $this->assertEqualsWithDelta(20.0, (float) $po->profit_tax_amount, 0.001); // 1%
        $this->assertEqualsWithDelta(2260.0, (float) $po->total_amount, 0.001);    // 2000 + 280 − 20
    }

    public function test_total_skips_one_percent_when_supplier_exempt(): void
    {
        $po = $this->poWithItems(applyProfitTax: false);

        $po->recalculateTotal();

        $this->assertEqualsWithDelta(2000.0, (float) $po->subtotal, 0.001);
        $this->assertEqualsWithDelta(280.0, (float) $po->vat_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $po->profit_tax_amount, 0.001);
        $this->assertEqualsWithDelta(2280.0, (float) $po->total_amount, 0.001);    // 2000 + 280 − 0
    }

    public function test_supplier_profit_tax_exempt_is_boolean_cast(): void
    {
        $supplier = Supplier::factory()->create(['profit_tax_exempt' => true]);

        $this->assertIsBool($supplier->fresh()->profit_tax_exempt);
        $this->assertTrue($supplier->fresh()->profit_tax_exempt);
    }
}
