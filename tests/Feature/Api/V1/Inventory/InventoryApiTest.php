<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Inventory;

use App\Enums\ItemType;
use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Item;
use Tests\Feature\Api\V1\ApiTestCase;

class InventoryApiTest extends ApiTestCase
{
    // ---------------------------------------------------------------- levels

    public function test_it_lists_stock_balances_with_the_three_quantities(): void
    {
        $item = Item::factory()->create(['unit_cost' => 100]);
        Inventory::create([
            'item_id' => $item->id,
            'warehouse_type' => WarehouseType::RawMaterials,
            'on_hand_quantity' => 120,
            'on_hold_quantity' => 20,
        ]);

        $response = $this->actingAsApi($this->userWith(['transactions.view']))
            ->apiGet(self::BASE.'/inventory');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);

        // The distinction is the whole point: on_hand is what is physically
        // there, available is what may still be promised.
        $response->assertJsonPath('data.0.on_hand', '120.0000');
        $response->assertJsonPath('data.0.on_hold', '20.0000');
        $response->assertJsonPath('data.0.available', '100.0000');

        // On-hand valued at the item's current standard cost.
        $response->assertJsonPath('data.0.value', '12000.00');
    }

    public function test_it_filters_balances_below_the_minimum(): void
    {
        $low = Item::factory()->create(['minimum_stock' => 100]);
        $healthy = Item::factory()->create(['minimum_stock' => 1]);

        foreach ([[$low, 5], [$healthy, 500]] as [$item, $qty]) {
            Inventory::create([
                'item_id' => $item->id,
                'warehouse_type' => WarehouseType::RawMaterials,
                'on_hand_quantity' => $qty,
                'on_hold_quantity' => 0,
            ]);
        }

        $this->actingAsApi($this->userWith(['transactions.view']))
            ->apiGet(self::BASE.'/inventory?filter[below_minimum]=true')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.item_id', $low->id);
    }

    public function test_balances_are_read_only(): void
    {
        // Stock moves because a document was posted, never because a balance
        // was written. A writable balance could put the ledger and the on-hand
        // figure out of step with nothing to reconcile them against.
        $this->actingAsApi($this->admin())
            ->apiPost(self::BASE.'/inventory', ['on_hand' => 999])
            ->assertStatus(405);
    }

    // ---------------------------------------------------------------- ledger

    public function test_it_lists_stock_movements(): void
    {
        $item = Item::factory()->create();

        $user = $this->userWith(['transactions.view']);

        InventoryTransaction::create([
            'item_id' => $item->id,
            'type' => TransactionType::In,
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => 40,
            'unit_cost' => 1000,
            'performed_by' => $user->id,
        ]);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/inventory-transactions');

        $response->assertOk();
        $response->assertJsonPath('data.0.movement.value', 'in');
        $response->assertJsonPath('data.0.quantity', '40.0000');
    }

    public function test_it_filters_the_ledger_to_real_stock_movements(): void
    {
        $item = Item::factory()->create();
        $user = $this->userWith(['transactions.view']);

        foreach ([TransactionType::In, TransactionType::Out, TransactionType::Hold, TransactionType::Release] as $type) {
            InventoryTransaction::create([
                'item_id' => $item->id,
                'type' => $type,
                'warehouse_type' => WarehouseType::RawMaterials,
                'quantity' => 1,
                'unit_cost' => 1,
                'performed_by' => $user->id,
            ]);
        }

        // hold/release change availability, not stock value — anything valuing
        // the stock must exclude them, so the filter has to allow that.
        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/inventory-transactions?filter[movement]=in,out')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    }

    // ------------------------------------------------------------ stock card

    public function test_the_stock_card_carries_a_running_balance_and_totals(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 100]);
        $user = $this->userWith(['transactions.view', 'items.view']);

        InventoryTransaction::create([
            'item_id' => $item->id, 'type' => TransactionType::In,
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => 40, 'unit_cost' => 100, 'performed_by' => $user->id,
        ]);
        InventoryTransaction::create([
            'item_id' => $item->id, 'type' => TransactionType::Out,
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => 10, 'unit_cost' => 100, 'performed_by' => $user->id,
        ]);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/items/'.$item->id.'/stock-card');

        $response->assertOk();
        $response->assertJsonPath('data.warehouse.value', 'raw_materials');
        $response->assertJsonCount(2, 'data.rows');

        $response->assertJsonPath('data.rows.0.in_qty', '40.0000');
        $response->assertJsonPath('data.rows.0.in_value', '4000.00');
        $response->assertJsonPath('data.rows.1.out_qty', '10.0000');

        $response->assertJsonPath('data.totals.quantity', '30.0000');
        $response->assertJsonPath('data.totals.value', '3000.00');
    }

    public function test_the_stock_card_reshapes_the_services_floats_into_the_api_contract(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $user = $this->userWith(['transactions.view', 'items.view']);

        InventoryTransaction::create([
            'item_id' => $item->id, 'type' => TransactionType::In,
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => 5, 'unit_cost' => 12.5, 'performed_by' => $user->id,
        ]);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/items/'.$item->id.'/stock-card');

        $response->assertOk();

        // The service returns floats and Carbon instances for the panel's
        // Blade view. Passing those through would have quietly broken the
        // API's promise that decimals are strings and dates are ISO-8601.
        $this->assertIsString($response->json('data.rows.0.in_price'));
        $this->assertIsString($response->json('data.rows.0.balance_value'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T/',
            (string) $response->json('data.rows.0.date'),
        );

        // Columns that do not apply to this row are null, not zero: "no issue
        // on this line" is not the same as "issued nothing".
        $this->assertNull($response->json('data.rows.0.out_qty'));
    }

    public function test_the_stock_card_rejects_an_unknown_warehouse(): void
    {
        $item = Item::factory()->create();

        $response = $this->actingAsApi($this->userWith(['transactions.view', 'items.view']))
            ->apiGet(self::BASE.'/items/'.$item->id.'/stock-card?warehouse=basement');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
    }

    // ------------------------------------------------------------------ RBAC

    public function test_inventory_endpoints_are_permission_gated(): void
    {
        $item = Item::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/inventory')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/inventory-transactions')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/items/'.$item->id.'/stock-card')->assertForbidden();
    }

    public function test_a_token_without_the_inventory_ability_is_refused(): void
    {
        $response = $this->actingAsApi($this->userWith(['transactions.view']), ['sales'])
            ->apiGet(self::BASE.'/inventory');

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    public function test_it_requires_a_token(): void
    {
        $this->apiGet(self::BASE.'/inventory')->assertUnauthorized();
    }
}
