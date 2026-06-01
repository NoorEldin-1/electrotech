<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemType;
use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryService::class);
        // performed_by falls back to Auth::id() ?? 1; make user #1 exist.
        $this->actingAs(User::factory()->create());
    }

    public function test_add_stock_lands_in_home_warehouse_with_cost(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 12.50]);

        $tx = $this->service->addStock($item, 10);

        $this->assertSame(TransactionType::In, $tx->type);
        $this->assertSame(WarehouseType::RawMaterials, $tx->warehouse_type);
        $this->assertEquals(12.50, (float) $tx->unit_cost);
        $this->assertEquals(10, $item->fresh()->quantityIn(WarehouseType::RawMaterials));
    }

    public function test_add_stock_to_explicit_warehouse(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        $this->service->addStock($item, 5, null, null, WarehouseType::FinishedGoods);

        $this->assertEquals(0, $item->fresh()->quantityIn(WarehouseType::RawMaterials));
        $this->assertEquals(5, $item->fresh()->quantityIn(WarehouseType::FinishedGoods));
    }

    public function test_deduct_stock_throws_on_insufficient(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $this->service->addStock($item, 3);

        $this->expectException(\RuntimeException::class);
        $this->service->deductStock($item, 5);
    }

    public function test_hold_then_release_round_trips(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $this->service->addStock($item, 10);

        $this->service->holdStock($item, 4);
        $this->assertEquals(6, $item->fresh()->availableIn(WarehouseType::RawMaterials));

        $this->service->releaseStock($item, 4);
        $this->assertEquals(10, $item->fresh()->availableIn(WarehouseType::RawMaterials));
    }

    public function test_transfer_moves_quantity_between_warehouses(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $this->service->addStock($item, 20, null, null, WarehouseType::RawMaterials);

        [$out, $in] = $this->service->transferStock($item, 8, WarehouseType::RawMaterials, WarehouseType::WorkInProgress);

        $fresh = $item->fresh();
        $this->assertEquals(12, $fresh->quantityIn(WarehouseType::RawMaterials));
        $this->assertEquals(8, $fresh->quantityIn(WarehouseType::WorkInProgress));
        $this->assertSame(TransactionType::Out, $out->type);
        $this->assertSame(WarehouseType::RawMaterials, $out->warehouse_type);
        $this->assertSame(TransactionType::In, $in->type);
        $this->assertSame(WarehouseType::WorkInProgress, $in->warehouse_type);
    }

    public function test_transfer_rejects_insufficient_source_stock(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $this->service->addStock($item, 5, null, null, WarehouseType::RawMaterials);

        $this->expectException(\RuntimeException::class);
        $this->service->transferStock($item, 50, WarehouseType::RawMaterials, WarehouseType::WorkInProgress);
    }

    public function test_transfer_rejects_same_warehouse(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $this->service->addStock($item, 5);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transferStock($item, 1, WarehouseType::RawMaterials, WarehouseType::RawMaterials);
    }

    public function test_total_available_sums_across_warehouses(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $this->service->addStock($item, 10, null, null, WarehouseType::RawMaterials);
        $this->service->addStock($item, 7, null, null, WarehouseType::WorkInProgress);

        $this->assertEquals(17, $item->fresh()->available_quantity);
    }
}
