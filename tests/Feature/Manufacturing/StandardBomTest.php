<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\BomStatus;
use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\IssueVoucherService;
use App\Services\WorkOrderMaterialService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardBomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * Build a finished good with a standard BOM of one raw line.
     *
     * @return array{0: Item, 1: Item, 2: Bom}
     */
    private function makeProductWithStandardBom(float $qty = 5, float $waste = 0, float $unitCost = 4): array
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => $unitCost]);

        $bom = Bom::factory()->standard($product)->create(['version' => 1]);
        BomItem::factory()->create([
            'bom_id' => $bom->id,
            'item_id' => $raw->id,
            'quantity' => $qty,
            'waste_percentage' => $waste,
        ]);

        return [$product, $raw, $bom];
    }

    public function test_latest_approved_standard_bom_is_resolved(): void
    {
        [$product] = $this->makeProductWithStandardBom();

        // A newer but DRAFT standard BOM must be ignored.
        Bom::factory()->standard($product)->create(['version' => 2, 'status' => BomStatus::Draft]);
        // A project BOM for an unrelated project must be ignored.
        Bom::factory()->create(['status' => BomStatus::Approved]);

        $resolved = $product->fresh()->latestApprovedStandardBom();

        $this->assertNotNull($resolved);
        $this->assertSame(1, $resolved->version);
        $this->assertNull($resolved->project_id);
    }

    public function test_fetch_scales_quantity_by_planned_and_pulls_unit_cost(): void
    {
        [$product, $raw] = $this->makeProductWithStandardBom(qty: 5, waste: 10, unitCost: 4);
        $wo = WorkOrder::factory()->create([
            'output_item_id' => $product->id,
            'bom_id' => null,
            'planned_quantity' => 3,
        ]);

        $lines = app(WorkOrderMaterialService::class)->fetchStandardMaterials($wo);

        $this->assertCount(1, $lines);
        $this->assertSame($raw->id, $lines[0]['item_id']);
        // 5 × (1 + 10%) × 3 planned = 16.5
        $this->assertEqualsWithDelta(16.5, $lines[0]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $lines[0]['unit_cost'], 0.0001);
        $this->assertFalse($lines[0]['is_manual']);
    }

    public function test_fetch_throws_when_no_standard_bom(): void
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $wo = WorkOrder::factory()->create(['output_item_id' => $product->id, 'bom_id' => null]);

        $this->expectException(\RuntimeException::class);
        app(WorkOrderMaterialService::class)->fetchStandardMaterials($wo);
    }

    public function test_editing_order_materials_does_not_change_standard_bom(): void
    {
        [$product, $raw, $bom] = $this->makeProductWithStandardBom(qty: 5);
        $wo = WorkOrder::factory()->create([
            'output_item_id' => $product->id,
            'bom_id' => null,
            'planned_quantity' => 1,
        ]);

        // Manual override on the order: 4.5 instead of the standard 5.
        $wo->materials()->create([
            'item_id' => $raw->id,
            'quantity' => 4.5,
            'unit_cost' => 4,
            'is_manual' => true,
        ]);

        // The product's standard recipe is untouched.
        $this->assertEqualsWithDelta(5.0, (float) $bom->fresh()->items->first()->quantity, 0.0001);
        $this->assertEqualsWithDelta(4.5, (float) $wo->materials->first()->quantity, 0.0001);
    }

    public function test_issue_voucher_built_from_order_materials_not_bom(): void
    {
        $project = Project::factory()->create(['actual_cost' => 0]);
        [$product, $raw] = $this->makeProductWithStandardBom(qty: 5, unitCost: 4);
        $wo = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'output_item_id' => $product->id,
            'bom_id' => null,
        ]);
        $wo->materials()->create([
            'item_id' => $raw->id,
            'quantity' => 7,
            'unit_cost' => 4,
            'is_manual' => true,
        ]);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);

        $this->assertSame(VoucherStatus::Draft, $voucher->status);
        $this->assertCount(1, $voucher->lines);
        $this->assertEqualsWithDelta(7.0, (float) $voucher->lines->first()->quantity, 0.0001);
    }

    public function test_issue_voucher_falls_back_to_bom_when_no_materials(): void
    {
        $project = Project::factory()->create();
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $bom = Bom::factory()->create(['project_id' => $project->id]);
        BomItem::factory()->create(['bom_id' => $bom->id, 'item_id' => $raw->id, 'quantity' => 9, 'waste_percentage' => 0]);
        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'bom_id' => $bom->id]);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);

        $this->assertCount(1, $voucher->lines);
        $this->assertEqualsWithDelta(9.0, (float) $voucher->lines->first()->quantity, 0.0001);
    }

    public function test_issue_voucher_throws_without_materials_or_bom(): void
    {
        $wo = WorkOrder::factory()->create(['bom_id' => null]);

        $this->expectException(\RuntimeException::class);
        app(IssueVoucherService::class)->createFromWorkOrder($wo);
    }
}
