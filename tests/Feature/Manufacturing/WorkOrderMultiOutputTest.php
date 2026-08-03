<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\ItemType;
use App\Enums\WarehouseType;
use App\Enums\WorkOrderStatus;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use App\Services\IssueVoucherService;
use App\Services\WorkOrderMaterialService;
use App\Services\WorkOrderService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المنتجات التامة — a manufacturing order produces several finished products,
 * each with its own planned quantity. Everything downstream has to follow:
 * the order's total plan, the merged material table, the issue vouchers cut
 * from it, and the per-product production entries at completion.
 */
class WorkOrderMultiOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * A finished product whose standard recipe is `$qty` of `$raw`.
     */
    private function productMadeOf(Item $raw, float $qty): Item
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $bom = Bom::factory()->standard($product)->create(['version' => 1]);
        BomItem::factory()->create([
            'bom_id' => $bom->id,
            'item_id' => $raw->id,
            'quantity' => $qty,
            'waste_percentage' => 0,
        ]);

        return $product;
    }

    public function test_materials_merge_the_recipes_of_every_product_scaled_by_its_own_quantity(): void
    {
        $shared = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $onlyB = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 7]);

        $productA = $this->productMadeOf($shared, 2);

        $productB = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $bomB = Bom::factory()->standard($productB)->create(['version' => 1]);
        BomItem::factory()->create(['bom_id' => $bomB->id, 'item_id' => $shared->id, 'quantity' => 3, 'waste_percentage' => 0]);
        BomItem::factory()->create(['bom_id' => $bomB->id, 'item_id' => $onlyB->id, 'quantity' => 1, 'waste_percentage' => 0]);

        $result = app(WorkOrderMaterialService::class)->standardMaterialsForOutputs([
            ['item_id' => $productA->id, 'planned_quantity' => 5],  // 2 × 5 = 10 shared
            ['item_id' => $productB->id, 'planned_quantity' => 2],  // 3 × 2 = 6 shared, 1 × 2 = 2 onlyB
        ]);

        $lines = collect($result['lines'])->keyBy('item_id');

        $this->assertCount(2, $lines, 'The shared raw material must collapse into ONE line.');
        $this->assertEqualsWithDelta(16.0, $lines[$shared->id]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $lines[$onlyB->id]['quantity'], 0.0001);
        $this->assertSame([], $result['missing']);
    }

    public function test_a_product_without_a_standard_recipe_is_named_but_does_not_sink_the_fetch(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $withRecipe = $this->productMadeOf($raw, 2);
        $withoutRecipe = Item::factory()->create(['type' => ItemType::FinishedGood]);

        $result = app(WorkOrderMaterialService::class)->standardMaterialsForOutputs([
            ['item_id' => $withRecipe->id, 'planned_quantity' => 3],
            ['item_id' => $withoutRecipe->id, 'planned_quantity' => 4],
        ]);

        $this->assertCount(1, $result['lines']);
        $this->assertEqualsWithDelta(6.0, $result['lines'][0]['quantity'], 0.0001);
        $this->assertSame([$withoutRecipe->name], $result['missing']);
    }

    public function test_a_product_line_with_no_quantity_yet_contributes_nothing(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial]);
        $product = $this->productMadeOf($raw, 2);

        $result = app(WorkOrderMaterialService::class)->standardMaterialsForOutputs([
            ['item_id' => $product->id, 'planned_quantity' => 0],
        ]);

        $this->assertSame([], $result['lines']);
    }

    public function test_the_order_plan_is_derived_from_the_product_lines(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $first = $this->productMadeOf($raw, 1);
        $second = $this->productMadeOf($raw, 1);

        $wo = WorkOrder::factory()->create(['planned_quantity' => 999, 'output_item_id' => null]);
        $wo->outputs()->create(['item_id' => $first->id, 'planned_quantity' => 6]);
        $wo->outputs()->create(['item_id' => $second->id, 'planned_quantity' => 4]);
        $wo->materials()->create(['item_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 4]);

        $wo->syncDerivedPlan();
        $wo->refresh();

        $this->assertEqualsWithDelta(10.0, (float) $wo->planned_quantity, 0.0001);
        // The first product is mirrored onto output_item_id for the readers
        // that still expect a single product.
        $this->assertSame($first->id, $wo->output_item_id);
        // The estimate now comes from the material plan, not a linked BOM.
        $this->assertEqualsWithDelta(40.0, (float) $wo->estimated_cost, 0.01);
    }

    public function test_a_product_line_with_no_quantity_blocks_the_approval(): void
    {
        $product = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $wo = WorkOrder::factory()->draft()->create(['planned_quantity' => 5]);
        $wo->outputs()->create(['item_id' => $product->id, 'planned_quantity' => 0]);

        $this->expectException(\RuntimeException::class);

        app(WorkOrderService::class)->approveOrder($wo->fresh());
    }

    public function test_qa_submission_records_quantities_per_product_and_sums_them_onto_the_order(): void
    {
        $productA = Item::factory()->create(['type' => ItemType::FinishedGood]);
        $productB = Item::factory()->create(['type' => ItemType::FinishedGood]);

        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress]);
        $outputA = $wo->outputs()->create(['item_id' => $productA->id, 'planned_quantity' => 6]);
        $outputB = $wo->outputs()->create(['item_id' => $productB->id, 'planned_quantity' => 4]);

        app(WorkOrderService::class)->submitForQa($wo, [
            ['output_id' => $outputA->id, 'produced_quantity' => 5, 'waste_quantity' => 1],
            ['output_id' => $outputB->id, 'produced_quantity' => 4, 'waste_quantity' => 0],
        ]);

        $this->assertEqualsWithDelta(5.0, (float) $outputA->fresh()->produced_quantity, 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $outputA->fresh()->waste_quantity, 0.0001);

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::QaReview, $wo->status);
        $this->assertEqualsWithDelta(9.0, (float) $wo->produced_quantity, 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $wo->waste_quantity, 0.0001);
    }

    public function test_completion_produces_every_product_and_apportions_the_cost(): void
    {
        $inventory = app(InventoryService::class);
        $project = Project::factory()->create(['actual_cost' => 0]);

        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $inventory->addStock($raw, 100, null, null, WarehouseType::RawMaterials);

        $productA = $this->productMadeOf($raw, 2);
        $productB = $this->productMadeOf($raw, 2);

        $wo = WorkOrder::factory()->approved()->create([
            'project_id' => $project->id,
            'bom_id' => null,
            'status' => WorkOrderStatus::Pending,
            'planned_quantity' => 10,
        ]);
        $outputA = $wo->outputs()->create(['item_id' => $productA->id, 'planned_quantity' => 6]);
        $outputB = $wo->outputs()->create(['item_id' => $productB->id, 'planned_quantity' => 4]);
        // 2 × 6 + 2 × 4 = 20 units of raw at 4 = 80.
        $wo->materials()->create(['item_id' => $raw->id, 'quantity' => 20, 'unit_cost' => 4]);
        $wo->syncDerivedPlan();

        $wos = app(WorkOrderService::class);
        $wos->start($wo->fresh());

        $voucher = $wos->issueMaterials($wo->fresh());
        app(IssueVoucherService::class)->post($voucher);

        $wo->refresh();
        $wos->submitForQa($wo, [
            ['output_id' => $outputA->id, 'produced_quantity' => 6, 'waste_quantity' => 0],
            ['output_id' => $outputB->id, 'produced_quantity' => 3, 'waste_quantity' => 1],
        ]);
        $wos->approveQa($wo->fresh());
        $wos->complete($wo->fresh());

        // Every product landed in finished goods with its OWN quantity.
        $this->assertEquals(6, $productA->fresh()->quantityIn(WarehouseType::FinishedGoods));
        $this->assertEquals(3, $productB->fresh()->quantityIn(WarehouseType::FinishedGoods));

        // One production entry per product...
        $entries = $wo->fresh()->productionEntries()->get()->keyBy('output_item_id');
        $this->assertCount(2, $entries);
        $this->assertEqualsWithDelta(6.0, (float) $entries[$productA->id]->planned_quantity, 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $entries[$productB->id]->scrap_quantity, 0.0001);

        // ...and the apportioned costs still add up to the order's own figures
        // (80 issued, split 60/40 by planned share).
        $this->assertEqualsWithDelta(
            80.0,
            (float) $entries->sum(fn ($entry) => (float) $entry->actual_material_cost),
            0.01,
        );
        $this->assertEqualsWithDelta(48.0, (float) $entries[$productA->id]->actual_material_cost, 0.01);
        $this->assertEqualsWithDelta(32.0, (float) $entries[$productB->id]->actual_material_cost, 0.01);
    }
}
