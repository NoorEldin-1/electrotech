<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Models\AdditionVoucher;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

class PurchaseOrderApiTest extends ApiTestCase
{
    // ------------------------------------------------------------ happy path

    public function test_it_lists_purchase_orders(): void
    {
        PurchaseOrder::factory()->count(2)->create();

        $response = $this->actingAsApi($this->userWith(['purchase_orders.view']))
            ->apiGet(self::BASE.'/purchase-orders');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
    }

    public function test_it_creates_a_draft_with_a_server_generated_number(): void
    {
        $supplier = Supplier::factory()->create(['profit_tax_exempt' => false]);

        $response = $this->actingAsApi($this->userWith(['purchase_orders.create']))
            ->apiPost(self::BASE.'/purchase-orders', ['supplier_id' => $supplier->id]);

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^PO-\d{6}-\d{4}$/', $response->json('data.po_number'));
        $response->assertJsonPath('data.status.value', 'draft');
        $response->assertJsonPath('data.total_amount', '0.00');
    }

    public function test_the_profit_tax_default_follows_the_suppliers_exemption(): void
    {
        $exempt = Supplier::factory()->create(['profit_tax_exempt' => true]);
        $normal = Supplier::factory()->create(['profit_tax_exempt' => false]);
        $user = $this->userWith(['purchase_orders.create']);

        // An exempt supplier whose order silently deducted 1% would be
        // short-paid on every invoice, so the default is read from the
        // supplier rather than left to the client to remember.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/purchase-orders', ['supplier_id' => $exempt->id])
            ->assertCreated()
            ->assertJsonPath('data.apply_profit_tax', false);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/purchase-orders', ['supplier_id' => $normal->id])
            ->assertCreated()
            ->assertJsonPath('data.apply_profit_tax', true);
    }

    public function test_replacing_the_items_derives_the_whole_money_breakdown(): void
    {
        $order = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Draft,
            'apply_profit_tax' => true,
        ]);
        $item = Item::factory()->create();

        $response = $this->actingAsApi($this->userWith(['purchase_orders.edit']))
            ->apiJson('PUT', self::BASE.'/purchase-orders/'.$order->id.'/items', [
                'items' => [
                    ['item_id' => $item->id, 'quantity' => 100, 'unit_price' => 1000],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.subtotal', '100000.00');

        // 14% VAT added, 1% profit tax DEDUCTED. A client that summed the
        // three components naively would show the supplier ~2% too much,
        // which is why every part is published rather than re-derived.
        $response->assertJsonPath('data.vat_amount', '14000.00');
        $response->assertJsonPath('data.profit_tax_amount', '1000.00');
        $response->assertJsonPath('data.total_amount', '113000.00');
    }

    public function test_an_exempt_supplier_pays_no_profit_tax(): void
    {
        $order = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Draft,
            'apply_profit_tax' => false,
        ]);
        $item = Item::factory()->create();

        $this->actingAsApi($this->userWith(['purchase_orders.edit']))
            ->apiJson('PUT', self::BASE.'/purchase-orders/'.$order->id.'/items', [
                'items' => [['item_id' => $item->id, 'quantity' => 100, 'unit_price' => 1000]],
            ])
            ->assertOk()
            ->assertJsonPath('data.profit_tax_amount', '0.00')
            ->assertJsonPath('data.total_amount', '114000.00');
    }

    public function test_it_approves_a_complete_draft(): void
    {
        $order = $this->draftOrderWithOneLine();

        $this->actingAsApi($this->userWith(['purchase_orders.approve']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'submitted');

        $this->assertNotNull($order->fresh()->approved_at);
    }

    // ------------------------------------------------------------- receiving

    public function test_receiving_raises_and_posts_an_addition_voucher(): void
    {
        $order = $this->draftOrderWithOneLine();
        $line = $order->items()->sole();

        $this->actingAsApi($this->userWith(['purchase_orders.approve']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')->assertOk();

        $response = $this->actingAsApi($this->userWith(['purchase_orders.receive']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/receive', [
                'items' => [(string) $line->id => 40],
                'invoice_number' => 'INV-88213',
            ]);

        // The voucher comes back, not the order: its number is what the
        // warehouse writes on the paperwork.
        $response->assertCreated();
        $response->assertJsonPath('data.type', 'addition_voucher');
        $response->assertJsonPath('data.status.value', 'posted');
        $response->assertJsonPath('data.purchase_order_id', $order->id);

        // Posting the voucher is what added the stock — exactly once.
        $this->assertSame(1, AdditionVoucher::count());
        $this->assertSame(
            40.0,
            (float) Inventory::where('item_id', $line->item_id)->sum('on_hand_quantity'),
        );

        // And the order closed itself by comparing ordered against received.
        $this->assertSame(40.0, (float) $line->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
    }

    public function test_receiving_everything_marks_the_order_received(): void
    {
        $order = $this->draftOrderWithOneLine(quantity: 40);
        $line = $order->items()->sole();

        $this->actingAsApi($this->userWith(['purchase_orders.approve']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')->assertOk();

        $this->actingAsApi($this->userWith(['purchase_orders.receive']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/receive', [
                'items' => [(string) $line->id => 40],
            ])->assertCreated();

        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
    }

    public function test_receiving_more_than_was_ordered_is_refused(): void
    {
        $order = $this->draftOrderWithOneLine(quantity: 100);
        $line = $order->items()->sole();

        $this->actingAsApi($this->userWith(['purchase_orders.approve']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')->assertOk();

        $response = $this->actingAsApi($this->userWith(['purchase_orders.receive']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/receive', [
                'items' => [(string) $line->id => 200],
            ]);

        // Over-receipt means the delivery note and the order disagree.
        // Silently capping it at 100 would hide that from everyone.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        // The whole receipt is one transaction, so nothing was added.
        $this->assertSame(0, AdditionVoucher::where('status', VoucherStatus::Posted)->count());
        $this->assertSame(0.0, (float) $line->fresh()->received_quantity);
    }

    public function test_a_service_refusal_reaches_the_client_as_a_business_rule_not_a_500(): void
    {
        // The services in this codebase signal business refusals with
        // \RuntimeException carrying a localized message; the panel catches it
        // and shows that message. Without the renderer's origin check the same
        // refusal would arrive here as an opaque 500 and the client could not
        // tell a refusal from an outage.
        $order = $this->draftOrderWithOneLine();
        $line = $order->items()->sole();

        $response = $this->actingAsApi($this->userWith(['purchase_orders.receive']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/receive', [
                'items' => [(string) $line->id => 999999],
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertNotSame('', (string) $response->json('error.message'));
    }

    // --------------------------------------------------------- business rule

    public function test_an_order_with_no_lines_cannot_be_approved(): void
    {
        $supplier = Supplier::factory()->create();
        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft,
        ]);

        $response = $this->actingAsApi($this->userWith(['purchase_orders.approve']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve');

        // A receipt could never complete it, so it would sit in Submitted
        // forever.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    public function test_an_approved_order_can_no_longer_be_edited(): void
    {
        $order = $this->draftOrderWithOneLine();
        $item = Item::factory()->create();

        $this->actingAsApi($this->userWith(['purchase_orders.approve']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')->assertOk();

        $user = $this->userWith(['purchase_orders.edit', 'purchase_orders.delete']);

        foreach ([
            fn () => $this->actingAsApi($user)->apiPatch(self::BASE.'/purchase-orders/'.$order->id, ['notes' => 'x']),
            fn () => $this->actingAsApi($user)->apiJson('PUT', self::BASE.'/purchase-orders/'.$order->id.'/items', [
                'items' => [['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1]],
            ]),
            fn () => $this->actingAsApi($user)->apiDelete(self::BASE.'/purchase-orders/'.$order->id),
        ] as $call) {
            $response = $call();
            $response->assertStatus(422);
            $this->assertErrorEnvelope($response, 'business_rule_violated');
        }
    }

    public function test_approving_twice_is_refused(): void
    {
        $order = $this->draftOrderWithOneLine();
        $user = $this->userWith(['purchase_orders.approve']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')->assertOk();

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')
            ->assertStatus(422);
    }

    // ----------------------------------------------------------- validation

    public function test_it_requires_an_existing_supplier(): void
    {
        $response = $this->actingAsApi($this->userWith(['purchase_orders.create']))
            ->apiPost(self::BASE.'/purchase-orders', ['supplier_id' => 99999]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['details' => ['supplier_id']]]);
    }

    public function test_a_zero_price_line_is_allowed_but_a_zero_quantity_is_not(): void
    {
        $order = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Draft]);
        $item = Item::factory()->create();
        $user = $this->userWith(['purchase_orders.edit']);

        // A free-of-charge line (a replacement part, a sample) is real; making
        // it invalid would push people to invent a nominal price that then
        // lands in the ledger.
        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/purchase-orders/'.$order->id.'/items', [
                'items' => [['item_id' => $item->id, 'quantity' => 5, 'unit_price' => 0]],
            ])
            ->assertOk();

        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/purchase-orders/'.$order->id.'/items', [
                'items' => [['item_id' => $item->id, 'quantity' => 0, 'unit_price' => 10]],
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------ RBAC

    public function test_approving_and_receiving_need_their_own_permissions(): void
    {
        $order = $this->draftOrderWithOneLine();
        $editor = $this->userWith(['purchase_orders.view', 'purchase_orders.edit']);

        $this->actingAsApi($editor)
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')
            ->assertForbidden();

        $this->actingAsApi($editor)
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/receive', ['items' => ['1' => 1]])
            ->assertForbidden();
    }

    public function test_purchase_order_endpoints_are_permission_gated(): void
    {
        $order = PurchaseOrder::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/purchase-orders')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/purchase-orders/'.$order->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/purchase-orders', [])->assertForbidden();
        $this->actingAsApi($user)->apiDelete(self::BASE.'/purchase-orders/'.$order->id)->assertForbidden();
    }

    public function test_a_token_without_the_procurement_ability_is_refused(): void
    {
        $response = $this->actingAsApi($this->userWith(['purchase_orders.view']), ['sales'])
            ->apiGet(self::BASE.'/purchase-orders');

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    // ---------------------------------------------------------- idempotency

    public function test_a_replayed_receipt_does_not_add_stock_twice(): void
    {
        $order = $this->draftOrderWithOneLine(quantity: 100);
        $line = $order->items()->sole();

        $this->actingAsApi($this->userWith(['purchase_orders.approve']))
            ->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/approve')->assertOk();

        $key = (string) Str::uuid();
        $this->actingAsApi($this->userWith(['purchase_orders.receive']));

        $payload = ['items' => [(string) $line->id => 40]];

        $this->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/receive', $payload, ['Idempotency-Key' => $key])
            ->assertCreated();
        $this->apiPost(self::BASE.'/purchase-orders/'.$order->id.'/receive', $payload, ['Idempotency-Key' => $key])
            ->assertCreated();

        // This is the failure the whole idempotency layer exists to stop: a
        // warehouse tablet retrying a receipt on a flaky link and booking the
        // delivery twice.
        $this->assertSame(1, AdditionVoucher::count());
        $this->assertSame(40.0, (float) Inventory::where('item_id', $line->item_id)->sum('on_hand_quantity'));
    }

    public function test_it_requires_a_token(): void
    {
        $this->apiGet(self::BASE.'/purchase-orders')->assertUnauthorized();
    }

    // ----------------------------------------------------------------- setup

    private function draftOrderWithOneLine(float $quantity = 100): PurchaseOrder
    {
        $supplier = Supplier::factory()->create(['profit_tax_exempt' => false]);
        $item = Item::factory()->create();

        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft,
            'apply_profit_tax' => true,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'received_quantity' => 0,
        ]);

        // A landing place for the stock a receipt will add.
        Inventory::firstOrCreate(
            ['item_id' => $item->id, 'warehouse_type' => WarehouseType::homeFor($item->type)],
            ['on_hand_quantity' => 0, 'on_hold_quantity' => 0],
        );

        $order->recalculateTotal();

        return $order->fresh();
    }
}
