<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 8: a purchase order can be printed as an internal PDF document, gated
 * by purchase_orders.print.
 */
class PurchaseOrderPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function poWithLine(): PurchaseOrder
    {
        $supplier = Supplier::factory()->create();
        $po = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id]);
        $item = Item::factory()->create();
        $po->items()->create(['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 50]);

        return $po;
    }

    public function test_authorized_user_can_print_in_english_and_arabic(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchase_orders.print');
        $this->actingAs($user);

        $po = $this->poWithLine();

        foreach (['en', 'ar'] as $lang) {
            $response = $this->get(route('purchase_orders.pdf', ['purchaseOrder' => $po, 'lang' => $lang]));
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        }
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $po = $this->poWithLine();

        $this->get(route('purchase_orders.pdf', ['purchaseOrder' => $po]))->assertForbidden();
    }
}
