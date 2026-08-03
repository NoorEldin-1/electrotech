<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Filament\Resources\IssueVoucherResource\Pages\CreateIssueVoucher;
use App\Filament\Resources\IssueVoucherResource\Pages\EditIssueVoucher;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reworked forms drive real state, not just render: the products repeater
 * re-derives the order's planned quantity and its material table, and the issue
 * voucher fills its lines from the picked manufacturing order.
 *
 * Mounts Edit rather than Create for the same reason as StandardBomFormSmokeTest
 * (Create's wo_number default uses a MySQL-only expression).
 */
class WorkOrderOutputsFormSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

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

    public function test_the_work_order_form_renders_with_several_products(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $wo = WorkOrder::factory()->create(['bom_id' => null]);
        $wo->outputs()->create(['item_id' => $this->productMadeOf($raw, 2)->id, 'planned_quantity' => 5]);
        $wo->outputs()->create(['item_id' => $this->productMadeOf($raw, 3)->id, 'planned_quantity' => 2]);
        $wo->materials()->create(['item_id' => $raw->id, 'quantity' => 16, 'unit_cost' => 4]);

        Livewire::test(EditWorkOrder::class, ['record' => $wo->getRouteKey()])->assertOk();
    }

    /**
     * Editing a product's quantity must move the order's total AND rescale the
     * material table in the same round trip — that is the whole point of the
     * per-product plan.
     */
    public function test_changing_a_product_quantity_rescales_the_plan_and_the_materials(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $product = $this->productMadeOf($raw, 2);

        $wo = WorkOrder::factory()->create(['bom_id' => null, 'planned_quantity' => 5]);
        $output = $wo->outputs()->create(['item_id' => $product->id, 'planned_quantity' => 5]);
        $wo->materials()->create(['item_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 4]);

        $component = Livewire::test(EditWorkOrder::class, ['record' => $wo->getRouteKey()]);

        $outputKey = array_key_first($component->get('data.outputs'));
        $component->set("data.outputs.{$outputKey}.planned_quantity", 8)->assertOk();

        $this->assertEquals(8, (float) $component->get('data.planned_quantity'));

        $materials = array_values($component->get('data.materials'));
        $this->assertCount(1, $materials);
        // 2 per unit × 8 units.
        $this->assertEqualsWithDelta(16.0, (float) $materials[0]['quantity'], 0.0001);

        // And the saved record agrees, because the page re-derives server-side.
        $component->call('save');
        $this->assertEqualsWithDelta(8.0, (float) $wo->fresh()->planned_quantity, 0.0001);
        $this->assertSame($product->id, $wo->fresh()->output_item_id);
        $this->assertEqualsWithDelta(8.0, (float) $output->fresh()->planned_quantity, 0.0001);
    }

    /**
     * A hand-edited material line is the office's decision; changing the plan
     * must not quietly overwrite it.
     */
    public function test_hand_edited_materials_are_not_overwritten_by_a_plan_change(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $product = $this->productMadeOf($raw, 2);

        $wo = WorkOrder::factory()->create(['bom_id' => null, 'planned_quantity' => 5]);
        $wo->outputs()->create(['item_id' => $product->id, 'planned_quantity' => 5]);
        $wo->materials()->create(['item_id' => $raw->id, 'quantity' => 99, 'unit_cost' => 4, 'is_manual' => true]);

        $component = Livewire::test(EditWorkOrder::class, ['record' => $wo->getRouteKey()]);
        $outputKey = array_key_first($component->get('data.outputs'));
        $component->set("data.outputs.{$outputKey}.planned_quantity", 8)->assertOk();

        $materials = array_values($component->get('data.materials'));
        $this->assertEqualsWithDelta(99.0, (float) $materials[0]['quantity'], 0.0001);
    }

    /**
     * إذن الصرف: picking the manufacturing order fills the lines with what it
     * still needs, and re-picking stays consistent.
     */
    public function test_picking_a_work_order_fills_the_voucher_lines(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $other = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 9]);

        $firstOrder = WorkOrder::factory()->create(['bom_id' => null]);
        $firstOrder->materials()->create(['item_id' => $raw->id, 'quantity' => 12, 'unit_cost' => 4]);

        $secondOrder = WorkOrder::factory()->create(['bom_id' => null]);
        $secondOrder->materials()->create(['item_id' => $other->id, 'quantity' => 3, 'unit_cost' => 9]);

        $voucher = IssueVoucher::create([
            'voucher_number' => 'ISV-TEST-0001',
            'work_order_id' => $firstOrder->id,
            'voucher_date' => now(),
            'status' => VoucherStatus::Draft,
        ]);
        // A real line, so the per-line "remaining required" hint and the live
        // over-issue warning are actually rendered — both of them reach for the
        // parent voucher from inside the repeater.
        $voucher->lines()->create(['item_id' => $raw->id, 'quantity' => 5, 'unit_cost' => 4]);

        $component = Livewire::test(EditIssueVoucher::class, ['record' => $voucher->getRouteKey()])->assertOk();

        $component->set('data.work_order_id', $firstOrder->id)->assertOk();
        $lines = array_values($component->get('data.lines'));
        $this->assertCount(1, $lines);
        $this->assertEquals($raw->id, $lines[0]['item_id']);
        $this->assertEqualsWithDelta(12.0, (float) $lines[0]['quantity'], 0.0001);

        // Switching orders replaces the lines cleanly rather than appending.
        $component->set('data.work_order_id', $secondOrder->id)->assertOk();
        $lines = array_values($component->get('data.lines'));
        $this->assertCount(1, $lines);
        $this->assertEquals($other->id, $lines[0]['item_id']);
        $this->assertEqualsWithDelta(3.0, (float) $lines[0]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(9.0, (float) $lines[0]['unit_cost'], 0.0001);
    }

    /**
     * Typing past the remaining requirement must surface the warning while the
     * form is still open — it renders from inside the same repeater state.
     */
    public function test_typing_past_the_requirement_renders_the_live_warning(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);

        $order = WorkOrder::factory()->create(['bom_id' => null]);
        $order->materials()->create(['item_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 4]);

        $voucher = IssueVoucher::create([
            'voucher_number' => 'ISV-TEST-0002',
            'work_order_id' => $order->id,
            'voucher_date' => now(),
            'status' => VoucherStatus::Draft,
        ]);
        $voucher->lines()->create(['item_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 4]);

        $warning = __('resources.issue_vouchers.excess.live_warning', ['items' => '']);
        $warning = trim(str_replace(':items', '', $warning));

        $component = Livewire::test(EditIssueVoucher::class, ['record' => $voucher->getRouteKey()])
            ->assertOk()
            ->assertDontSee($warning);

        $lineKey = array_key_first($component->get('data.lines'));
        $component->set("data.lines.{$lineKey}.quantity", 14)
            ->assertOk()
            ->assertSee($warning);
    }

    /**
     * The create screen has no voucher yet — every closure that reaches for the
     * record has to cope with that.
     */
    public function test_the_issue_voucher_create_form_renders(): void
    {
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]);
        $order = WorkOrder::factory()->create(['bom_id' => null]);
        $order->materials()->create(['item_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 4]);

        Livewire::test(CreateIssueVoucher::class)
            ->assertOk()
            ->set('data.work_order_id', $order->id)
            ->assertOk();
    }
}
