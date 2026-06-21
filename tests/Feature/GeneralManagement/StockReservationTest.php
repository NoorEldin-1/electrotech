<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\BomStatus;
use App\Enums\ItemType;
use App\Enums\ReservationStatus;
use App\Enums\WarehouseType;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\Project;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function stockedItem(float $onHand): Item
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 5]);
        app(InventoryService::class)->addStock($item, $onHand, null, null, WarehouseType::RawMaterials);

        return $item;
    }

    public function test_reserve_reduces_available_without_changing_on_hand(): void
    {
        $project = Project::factory()->active()->create();
        $item = $this->stockedItem(100);

        $reservation = app(ReservationService::class)
            ->reserveForProject($project, $item, 30, WarehouseType::RawMaterials);

        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertSame($project->id, $reservation->project_id);

        $fresh = $item->fresh();
        $this->assertEquals(100, $fresh->quantityIn(WarehouseType::RawMaterials));   // on-hand unchanged
        $this->assertEquals(70, $fresh->availableIn(WarehouseType::RawMaterials));   // available reduced
    }

    public function test_release_returns_availability_and_is_idempotent(): void
    {
        $project = Project::factory()->active()->create();
        $item = $this->stockedItem(100);
        $service = app(ReservationService::class);

        $reservation = $service->reserveForProject($project, $item, 30, WarehouseType::RawMaterials);
        $this->assertEquals(70, $item->fresh()->availableIn(WarehouseType::RawMaterials));

        $service->release($reservation);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Released, $reservation->status);
        $this->assertNotNull($reservation->released_at);
        $this->assertEquals(100, $item->fresh()->availableIn(WarehouseType::RawMaterials));

        // Releasing again is a no-op.
        $service->release($reservation);
        $this->assertEquals(100, $item->fresh()->availableIn(WarehouseType::RawMaterials));
    }

    public function test_reserving_above_available_throws(): void
    {
        $project = Project::factory()->active()->create();
        $item = $this->stockedItem(10);

        $this->expectException(\RuntimeException::class);
        app(ReservationService::class)->reserveForProject($project, $item, 20, WarehouseType::RawMaterials);
    }

    public function test_reservable_items_exclude_zero_and_fully_held_stock(): void
    {
        // Has free stock → reservable.
        $available = $this->stockedItem(10);

        // Never received any stock → not reservable.
        $zeroStock = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 5]);

        // On-hand but entirely held → 0 available → not reservable.
        $fullyHeld = $this->stockedItem(5);
        $project = Project::factory()->active()->create();
        app(ReservationService::class)->reserveForProject($project, $fullyHeld, 5, WarehouseType::RawMaterials);

        $ids = Item::withAvailableStockIn(WarehouseType::RawMaterials)->pluck('id');

        $this->assertTrue($ids->contains($available->id));
        $this->assertFalse($ids->contains($zeroStock->id));
        $this->assertFalse($ids->contains($fullyHeld->id));
    }

    public function test_reservable_items_are_scoped_to_the_requested_warehouse(): void
    {
        // Stock lives only in raw materials, so the item is reservable there
        // but not from the finished-goods store.
        $item = $this->stockedItem(10);

        $this->assertTrue(
            Item::withAvailableStockIn(WarehouseType::RawMaterials)->pluck('id')->contains($item->id)
        );
        $this->assertFalse(
            Item::withAvailableStockIn(WarehouseType::FinishedGoods)->pluck('id')->contains($item->id)
        );
    }

    public function test_reserve_approved_bom_reserves_each_line(): void
    {
        $project = Project::factory()->active()->create();
        $item = $this->stockedItem(50);

        $bom = Bom::factory()->create(['project_id' => $project->id, 'status' => BomStatus::Approved]);
        BomItem::factory()->create([
            'bom_id' => $bom->id,
            'item_id' => $item->id,
            'quantity' => 8,
            'waste_percentage' => 25, // total required = 8 × 1.25 = 10
        ]);

        $reservations = app(ReservationService::class)->reserveApprovedBomFor($project);

        $this->assertCount(1, $reservations);
        $this->assertEquals(10.0, (float) $reservations->first()->quantity);
        $this->assertEquals(40, $item->fresh()->availableIn(WarehouseType::RawMaterials));
        $this->assertSame(1, StockReservation::where('project_id', $project->id)->count());
    }
}
