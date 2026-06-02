<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\ItemType;
use App\Enums\WarehouseType;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use App\Services\IssueVoucherService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array{0: WorkOrder, 1: Item, 2: Project}
     */
    private function makeWorkOrderWithBom(float $unitCost, float $qty, float $waste = 0): array
    {
        $project = Project::factory()->active()->create(['actual_cost' => 0]);
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => $unitCost]);
        $bom = Bom::factory()->create(['project_id' => $project->id]);
        BomItem::factory()->create([
            'bom_id' => $bom->id,
            'item_id' => $item->id,
            'quantity' => $qty,
            'waste_percentage' => $waste,
        ]);
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'bom_id' => $bom->id]);

        return [$wo, $item, $project];
    }

    public function test_estimated_cost_snapshots_bom_total_on_creation(): void
    {
        [$wo] = $this->makeWorkOrderWithBom(unitCost: 10, qty: 5);

        // 5 × 10 = 50.
        $this->assertEquals(50.0, (float) $wo->estimated_cost);
    }

    public function test_estimated_cost_is_frozen_when_item_price_changes(): void
    {
        [$wo, $item] = $this->makeWorkOrderWithBom(unitCost: 10, qty: 5);
        $this->assertEquals(50.0, (float) $wo->estimated_cost);

        // Price doubles after the snapshot — the WO estimate must not move.
        $item->update(['unit_cost' => 20]);

        $this->assertEquals(50.0, (float) $wo->fresh()->estimated_cost);
    }

    public function test_actual_material_cost_accrues_from_posted_issue_voucher(): void
    {
        // qty 10 @ 4, no waste → estimate 40, issued 40, variance 0.
        [$wo, $item] = $this->makeWorkOrderWithBom(unitCost: 4, qty: 10);
        app(InventoryService::class)->addStock($item, 50, null, null, WarehouseType::RawMaterials);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        app(IssueVoucherService::class)->post($voucher);

        $fresh = $wo->fresh();
        $this->assertEquals(40.0, (float) $fresh->actual_material_cost);
        $this->assertEquals(40.0, (float) $fresh->estimated_cost);
        $this->assertEquals(0.0, $fresh->cost_variance);
    }

    public function test_waste_drives_a_positive_cost_variance(): void
    {
        // qty 10 @ 4, 50% waste → estimate uses quantity (40), issued uses
        // total-required 15 → actual 60, variance +20 (over budget).
        [$wo, $item] = $this->makeWorkOrderWithBom(unitCost: 4, qty: 10, waste: 50);
        app(InventoryService::class)->addStock($item, 50, null, null, WarehouseType::RawMaterials);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        app(IssueVoucherService::class)->post($voucher);

        $fresh = $wo->fresh();
        $this->assertEquals(40.0, (float) $fresh->estimated_cost);
        $this->assertEquals(60.0, (float) $fresh->actual_material_cost);
        $this->assertEquals(20.0, $fresh->cost_variance);
        $this->assertEqualsWithDelta(50.0, $fresh->cost_variance_percent, 0.001);
    }

    public function test_variance_percent_is_null_without_estimate(): void
    {
        $wo = WorkOrder::factory()->create(['bom_id' => null, 'estimated_cost' => 0, 'actual_material_cost' => 30]);

        $this->assertEquals(30.0, $wo->cost_variance);
        $this->assertNull($wo->cost_variance_percent);
    }
}
