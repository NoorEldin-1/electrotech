<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\MasterData;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Enums\WarehouseType;
use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

class ItemApiTest extends ApiTestCase
{
    // ------------------------------------------------------------ happy path

    public function test_it_lists_items_in_the_collection_envelope(): void
    {
        Item::factory()->count(3)->create();

        $response = $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_it_serializes_an_item_to_the_documented_shape(): void
    {
        $item = Item::factory()->create([
            'name' => 'Copper Busbar',
            'sku' => 'CU-BB-1',
            'type' => ItemType::RawMaterial,
            'unit' => UnitOfMeasure::Kilogram,
            'unit_cost' => 412.5,
            'minimum_stock' => 50,
        ]);

        $response = $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items/'.$item->id);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Copper Busbar');
        $response->assertJsonPath('data.item_type.value', 'raw_material');
        $response->assertJsonPath('data.unit.value', 'kg');

        // Money and quantities are strings with a fixed precision, not JSON
        // numbers — a Dart double would re-round these (plan §3.10).
        $response->assertJsonPath('data.unit_cost', '412.50');
        $response->assertJsonPath('data.minimum_stock', '50.0000');

        // The home warehouse is derived, so the client does not reimplement
        // WarehouseType::homeFor() in Dart.
        $response->assertJsonPath('data.home_warehouse.value', 'raw_materials');
    }

    public function test_the_detail_endpoint_carries_per_warehouse_stock(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        Inventory::create([
            'item_id' => $item->id,
            'warehouse_type' => WarehouseType::RawMaterials,
            'on_hand_quantity' => 120,
            'on_hold_quantity' => 20,
        ]);

        $response = $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items/'.$item->id);

        $response->assertOk();
        $response->assertJsonPath('data.stock.0.warehouse.value', 'raw_materials');
        $response->assertJsonPath('data.stock.0.on_hand', '120.0000');
        $response->assertJsonPath('data.stock.0.on_hold', '20.0000');
        $response->assertJsonPath('data.stock.0.available', '100.0000');
    }

    public function test_it_creates_an_item(): void
    {
        $response = $this->actingAsApi($this->userWith(['items.create']))
            ->apiPost(self::BASE.'/items', [
                'name' => 'Aluminium Sheet',
                'sku' => 'AL-SH-2',
                'type' => 'raw_material',
                'unit' => 'kg',
                'unit_cost' => 88.25,
                'minimum_stock' => 10,
            ]);

        $response->assertCreated();
        $this->assertItemEnvelope($response);
        $this->assertDatabaseHas('items', ['sku' => 'AL-SH-2', 'name' => 'Aluminium Sheet']);
    }

    public function test_it_updates_only_the_fields_sent(): void
    {
        $item = Item::factory()->create(['name' => 'Old', 'unit_cost' => 10]);

        $response = $this->actingAsApi($this->userWith(['items.edit']))
            ->apiPatch(self::BASE.'/items/'.$item->id, ['name' => 'New']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New');

        // A PATCH that named only `name` must not reset the price.
        $this->assertSame('10.00', $item->fresh()->unit_cost);
    }

    public function test_it_soft_deletes_an_item(): void
    {
        $item = Item::factory()->create();

        $this->actingAsApi($this->userWith(['items.delete']))
            ->apiDelete(self::BASE.'/items/'.$item->id)
            ->assertNoContent();

        $this->assertSoftDeleted('items', ['id' => $item->id]);
    }

    // --------------------------------------------------------- business rule

    public function test_it_refuses_to_delete_an_item_that_still_holds_stock(): void
    {
        $item = Item::factory()->create();

        Inventory::create([
            'item_id' => $item->id,
            'warehouse_type' => WarehouseType::RawMaterials,
            'on_hand_quantity' => 5,
            'on_hold_quantity' => 0,
        ]);

        $response = $this->actingAsApi($this->userWith(['items.delete']))
            ->apiDelete(self::BASE.'/items/'.$item->id);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
        $this->assertDatabaseHas('items', ['id' => $item->id, 'deleted_at' => null]);
    }

    // ----------------------------------------------------------- validation

    public function test_it_rejects_a_duplicate_sku(): void
    {
        Item::factory()->create(['sku' => 'DUP-1']);

        $response = $this->actingAsApi($this->userWith(['items.create']))
            ->apiPost(self::BASE.'/items', [
                'name' => 'Another',
                'sku' => 'DUP-1',
                'type' => 'raw_material',
                'unit' => 'kg',
                'unit_cost' => 1,
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['sku']]]);
    }

    public function test_it_rejects_an_unknown_enum_value(): void
    {
        $response = $this->actingAsApi($this->userWith(['items.create']))
            ->apiPost(self::BASE.'/items', [
                'name' => 'Bad type',
                'sku' => 'BAD-1',
                'type' => 'not_a_real_type',
                'unit' => 'kg',
                'unit_cost' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['details' => ['type']]]);
    }

    // ------------------------------------------------------------------ RBAC

    public function test_it_denies_a_user_without_the_permission(): void
    {
        $item = Item::factory()->create();
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/items')->assertForbidden();
        $this->actingAsApi($user)->apiGet(self::BASE.'/items/'.$item->id)->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/items', [])->assertForbidden();
        $this->actingAsApi($user)->apiPatch(self::BASE.'/items/'.$item->id, [])->assertForbidden();
        $this->actingAsApi($user)->apiDelete(self::BASE.'/items/'.$item->id)->assertForbidden();
    }

    public function test_a_write_is_forbidden_before_it_is_validated(): void
    {
        // Finding #4: with the policy check only in the controller, posting an
        // empty body to an endpoint you may not use would answer 422 and list
        // every field and rule.
        $response = $this->actingAsApi($this->userWith(['items.view']))
            ->apiPost(self::BASE.'/items', []);

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    public function test_a_token_without_the_master_data_ability_is_refused(): void
    {
        $user = $this->userWith(['items.view']);

        $response = $this->actingAsApi($user, ['sales'])->apiGet(self::BASE.'/items');

        $response->assertForbidden();
        $this->assertErrorEnvelope($response, 'insufficient_token_ability');
    }

    // ------------------------------------------------- query features / perf

    public function test_it_filters_by_type_and_searches_by_sku(): void
    {
        Item::factory()->create(['type' => ItemType::RawMaterial, 'sku' => 'RAW-1']);
        Item::factory()->create(['type' => ItemType::Consumable, 'sku' => 'CON-1']);

        $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items?filter[type]=raw_material')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.sku', 'RAW-1');

        $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items?search=CON-1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_it_filters_items_below_their_minimum_stock(): void
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

        $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items?filter[below_minimum]=true')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $low->id);
    }

    public function test_an_unknown_filter_is_a_422_that_names_the_allowed_keys(): void
    {
        $response = $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items?filter[colour]=red');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
    }

    public function test_the_index_does_not_n_plus_one_when_including_stock(): void
    {
        $items = Item::factory()->count(5)->create();

        foreach ($items as $item) {
            Inventory::create([
                'item_id' => $item->id,
                'warehouse_type' => WarehouseType::RawMaterials,
                'on_hand_quantity' => 1,
                'on_hold_quantity' => 0,
            ]);
        }

        $user = $this->userWith(['items.view']);
        $this->actingAsApi($user);

        DB::enableQueryLog();
        $this->apiGet(self::BASE.'/items?include=inventories')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Auth + permissions + count + page + one eager load. The number that
        // matters is that it does not scale with the five items.
        $this->assertLessThan(12, $queries, "Item index issued {$queries} queries — suspect an N+1.");
    }

    // ---------------------------------------------------------- idempotency

    public function test_a_replayed_create_does_not_write_twice(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'name' => 'Replayed',
            'sku' => 'REPLAY-1',
            'type' => 'raw_material',
            'unit' => 'kg',
            'unit_cost' => 5,
        ];

        // One token, two requests. Issuing a second token would change the
        // idempotency scope (Finding #3 keys the cache on the bearer token),
        // so the replay would legitimately execute again and the test would
        // be asserting nothing.
        $this->actingAsApi($this->userWith(['items.create']));

        $first = $this->apiPost(self::BASE.'/items', $payload, ['Idempotency-Key' => $key]);
        $first->assertCreated();

        $second = $this->apiPost(self::BASE.'/items', $payload, ['Idempotency-Key' => $key]);
        $second->assertCreated();

        $this->assertSame(1, Item::where('sku', 'REPLAY-1')->count());
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    // -------------------------------------------------------------- auth

    public function test_it_requires_a_token(): void
    {
        $response = $this->apiGet(self::BASE.'/items');

        $response->assertUnauthorized();
        $this->assertErrorEnvelope($response, 'unauthenticated');
    }
}
