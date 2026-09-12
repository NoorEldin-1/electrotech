<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Procurement;

use App\Enums\BomStatus;
use App\Enums\ItemType;
use App\Enums\ReservationStatus;
use App\Enums\WarehouseType;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Project;
use App\Models\StockReservation;
use Tests\Feature\Api\V1\ApiTestCase;

class StockReservationApiTest extends ApiTestCase
{
    public function test_it_holds_stock_for_an_operation(): void
    {
        $project = Project::factory()->create();
        $item = $this->stockedItem(onHand: 100);

        $response = $this->actingAsApi($this->userWith(['operations.reserve']))
            ->apiPost(self::BASE.'/stock-reservations', [
                'project_id' => $project->id,
                'item_id' => $item->id,
                'quantity' => 40,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status.value', 'active');
        $response->assertJsonPath('data.quantity', '40.0000');

        // A hold lowers what may be promised without moving anything
        // physically. Both halves of that matter.
        $level = Inventory::where('item_id', $item->id)->sole();
        $this->assertSame(100.0, (float) $level->on_hand_quantity);
        $this->assertSame(40.0, (float) $level->on_hold_quantity);
    }

    public function test_the_warehouse_defaults_to_the_items_home(): void
    {
        $project = Project::factory()->create();
        $item = $this->stockedItem(onHand: 50);

        $this->actingAsApi($this->userWith(['operations.reserve']))
            ->apiPost(self::BASE.'/stock-reservations', [
                'project_id' => $project->id,
                'item_id' => $item->id,
                'quantity' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.warehouse.value', 'raw_materials');
    }

    public function test_reserving_more_than_is_available_is_refused(): void
    {
        $project = Project::factory()->create();
        $item = $this->stockedItem(onHand: 30);

        $response = $this->actingAsApi($this->userWith(['operations.reserve']))
            ->apiPost(self::BASE.'/stock-reservations', [
                'project_id' => $project->id,
                'item_id' => $item->id,
                'quantity' => 40,
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        // The message names the item, the warehouse and what was actually
        // free, and is safe to show to the user as written.
        $this->assertStringContainsString('Available', $response->json('error.message'));
        $this->assertSame(0, StockReservation::count());
    }

    public function test_two_reservations_cannot_promise_the_same_stock_twice(): void
    {
        $project = Project::factory()->create();
        $item = $this->stockedItem(onHand: 100);
        $user = $this->userWith(['operations.reserve']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/stock-reservations', [
                'project_id' => $project->id, 'item_id' => $item->id, 'quantity' => 80,
            ])->assertCreated();

        // 100 on hand, 80 already held — only 20 may still be promised.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/stock-reservations', [
                'project_id' => $project->id, 'item_id' => $item->id, 'quantity' => 30,
            ])->assertStatus(422);
    }

    public function test_it_releases_a_reservation_back_to_available(): void
    {
        $project = Project::factory()->create();
        $item = $this->stockedItem(onHand: 100);
        $user = $this->userWith(['operations.reserve']);

        $id = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/stock-reservations', [
                'project_id' => $project->id, 'item_id' => $item->id, 'quantity' => 40,
            ])->json('data.id');

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/stock-reservations/'.$id.'/release')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'released');

        $level = Inventory::where('item_id', $item->id)->sole();
        $this->assertSame(0.0, (float) $level->on_hold_quantity);
        $this->assertSame(100.0, (float) $level->on_hand_quantity);
    }

    public function test_releasing_twice_is_refused_rather_than_silently_accepted(): void
    {
        $project = Project::factory()->create();
        $item = $this->stockedItem(onHand: 100);
        $user = $this->userWith(['operations.reserve']);

        $id = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/stock-reservations', [
                'project_id' => $project->id, 'item_id' => $item->id, 'quantity' => 10,
            ])->json('data.id');

        $this->actingAsApi($user)->apiPost(self::BASE.'/stock-reservations/'.$id.'/release')->assertOk();

        // The service is idempotent, so a 200 here would tell the caller a
        // release happened when nothing did.
        $response = $this->actingAsApi($user)->apiPost(self::BASE.'/stock-reservations/'.$id.'/release');
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    // ------------------------------------------------------- bulk BOM reserve

    public function test_it_reserves_an_operations_whole_approved_bom(): void
    {
        $project = Project::factory()->create();
        $first = $this->stockedItem(onHand: 500);
        $second = $this->stockedItem(onHand: 500);

        $bom = Bom::factory()->create([
            'project_id' => $project->id,
            'output_item_id' => null,
            'status' => BomStatus::Approved,
            'version' => 1,
        ]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $first->id, 'quantity' => 100, 'waste_percentage' => 5]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $second->id, 'quantity' => 50, 'waste_percentage' => 0]);

        $response = $this->actingAsApi($this->userWith(['operations.reserve', 'projects.view']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/reserve-approved-bom');

        $response->assertCreated();
        $response->assertJsonPath('meta.count', 2);

        // Each line is held at its WASTE-ADJUSTED quantity, which is what the
        // job will actually consume.
        $this->assertSame(105.0, (float) Inventory::where('item_id', $first->id)->sole()->on_hold_quantity);
        $this->assertSame(50.0, (float) Inventory::where('item_id', $second->id)->sole()->on_hold_quantity);
    }

    public function test_a_shortage_on_one_line_holds_nothing_at_all(): void
    {
        $project = Project::factory()->create();
        $plentiful = $this->stockedItem(onHand: 500);
        $short = $this->stockedItem(onHand: 1);

        $bom = Bom::factory()->create([
            'project_id' => $project->id,
            'output_item_id' => null,
            'status' => BomStatus::Approved,
        ]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $plentiful->id, 'quantity' => 100, 'waste_percentage' => 0]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $short->id, 'quantity' => 100, 'waste_percentage' => 0]);

        $response = $this->actingAsApi($this->userWith(['operations.reserve', 'projects.view']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/reserve-approved-bom');

        $response->assertStatus(422);

        // Without one transaction around the whole run, the first line would
        // stay held with nothing recording that the batch failed — and the
        // next attempt would double-hold it.
        $this->assertSame(0, StockReservation::count());
        $this->assertSame(0.0, (float) Inventory::where('item_id', $plentiful->id)->sole()->on_hold_quantity);
    }

    public function test_an_operation_with_no_approved_bom_reserves_nothing_without_erroring(): void
    {
        $project = Project::factory()->create();

        // Nothing to hold yet is a fact, not a failure.
        $this->actingAsApi($this->userWith(['operations.reserve', 'projects.view']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/reserve-approved-bom')
            ->assertCreated()
            ->assertJsonPath('meta.count', 0);
    }

    // ------------------------------------------------------------ list / RBAC

    public function test_it_filters_reservations_by_status(): void
    {
        $project = Project::factory()->create();
        $item = $this->stockedItem(onHand: 100);

        StockReservation::create([
            'project_id' => $project->id, 'item_id' => $item->id,
            'warehouse_type' => WarehouseType::RawMaterials, 'quantity' => 5,
            'status' => ReservationStatus::Active,
        ]);
        StockReservation::create([
            'project_id' => $project->id, 'item_id' => $item->id,
            'warehouse_type' => WarehouseType::RawMaterials, 'quantity' => 5,
            'status' => ReservationStatus::Released,
        ]);

        $this->actingAsApi($this->userWith(['operations.reserve']))
            ->apiGet(self::BASE.'/stock-reservations?filter[status]=active')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_reservation_endpoints_are_permission_gated(): void
    {
        $user = $this->userWithoutPermissions();

        $this->actingAsApi($user)->apiGet(self::BASE.'/stock-reservations')->assertForbidden();
        $this->actingAsApi($user)->apiPost(self::BASE.'/stock-reservations', [])->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->apiGet(self::BASE.'/stock-reservations')->assertUnauthorized();
    }

    // ----------------------------------------------------------------- setup

    private function stockedItem(float $onHand): Item
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        Inventory::create([
            'item_id' => $item->id,
            'warehouse_type' => WarehouseType::RawMaterials,
            'on_hand_quantity' => $onHand,
            'on_hold_quantity' => 0,
        ]);

        return $item;
    }
}
