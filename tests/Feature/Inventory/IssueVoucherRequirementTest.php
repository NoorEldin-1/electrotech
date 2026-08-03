<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Enums\WarehouseType;
use App\Exceptions\ExcessIssueException;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InventoryService;
use App\Services\IssueVoucherService;
use App\Services\ReturnVoucherService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الأصناف المنصرفة تتبع أمر التصنيع — picking a manufacturing order fills the
 * voucher with what it still needs, and posting more than that is stopped at
 * the gate unless it is knowingly approved.
 */
class IssueVoucherRequirementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
    }

    /**
     * A work order planning `$planned` of one raw material, with plenty of it
     * on the shelf.
     *
     * @return array{0: WorkOrder, 1: Item}
     */
    private function orderPlanning(float $planned = 10, float $unitCost = 4): array
    {
        $project = Project::factory()->create(['actual_cost' => 0]);
        $raw = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => $unitCost]);
        app(InventoryService::class)->addStock($raw, 500, null, null, WarehouseType::RawMaterials);

        $wo = WorkOrder::factory()->create(['project_id' => $project->id, 'bom_id' => null]);
        $wo->materials()->create(['item_id' => $raw->id, 'quantity' => $planned, 'unit_cost' => $unitCost]);

        return [$wo->fresh(), $raw];
    }

    public function test_suggested_lines_carry_the_planned_quantity_and_cost(): void
    {
        [$wo, $raw] = $this->orderPlanning(planned: 10, unitCost: 4);

        $lines = app(IssueVoucherService::class)->suggestedLinesFor($wo);

        $this->assertCount(1, $lines);
        $this->assertSame($raw->id, $lines[0]['item_id']);
        $this->assertEqualsWithDelta(10.0, $lines[0]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $lines[0]['unit_cost'], 0.0001);
    }

    public function test_a_second_voucher_only_proposes_what_is_still_owed(): void
    {
        [$wo, $raw] = $this->orderPlanning(planned: 10);

        // First voucher takes 4 of the 10 and is posted.
        $first = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        $first->lines()->first()->update(['quantity' => 4]);
        app(IssueVoucherService::class)->post($first->fresh());

        $lines = app(IssueVoucherService::class)->suggestedLinesFor($wo->fresh());

        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(6.0, $lines[0]['quantity'], 0.0001);
        $this->assertSame($raw->id, $lines[0]['item_id']);
    }

    public function test_returned_material_comes_back_into_the_requirement(): void
    {
        [$wo, $raw] = $this->orderPlanning(planned: 10);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        app(IssueVoucherService::class)->post($voucher->fresh());

        // Nothing left to issue…
        $this->assertSame([], app(IssueVoucherService::class)->suggestedLinesFor($wo->fresh()));

        // …until 3 come back on a posted return voucher.
        $return = app(ReturnVoucherService::class)->createFromWorkOrder($wo->fresh());
        $return->lines()->first()->update(['quantity' => 3]);
        app(ReturnVoucherService::class)->post($return->fresh());

        $lines = app(IssueVoucherService::class)->suggestedLinesFor($wo->fresh());

        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(3.0, $lines[0]['quantity'], 0.0001);
    }

    public function test_creating_a_voucher_when_nothing_is_owed_is_refused_with_a_clear_reason(): void
    {
        [$wo] = $this->orderPlanning(planned: 10);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        app(IssueVoucherService::class)->post($voucher->fresh());

        $this->expectException(\RuntimeException::class);
        app(IssueVoucherService::class)->createFromWorkOrder($wo->fresh());
    }

    public function test_a_sibling_draft_is_counted_by_the_suggestion_but_not_by_the_gate(): void
    {
        [$wo] = $this->orderPlanning(planned: 10);

        // A draft voucher already claims all 10.
        app(IssueVoucherService::class)->createFromWorkOrder($wo);

        // The suggestion for a NEW voucher therefore proposes nothing…
        $this->assertSame([], app(IssueVoucherService::class)->suggestedLinesFor($wo->fresh()));

        // …but the draft itself is not "over" anything: nothing has moved yet.
        $draft = $wo->fresh()->issueVouchers()->first();
        $this->assertSame([], app(IssueVoucherService::class)->excessReport($draft));
    }

    public function test_posting_more_than_the_plan_is_refused_and_names_the_item(): void
    {
        [$wo, $raw] = $this->orderPlanning(planned: 10);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        $voucher->lines()->first()->update(['quantity' => 13]);

        try {
            app(IssueVoucherService::class)->post($voucher->fresh());
            $this->fail('Posting past the material plan must be refused.');
        } catch (ExcessIssueException $e) {
            $this->assertCount(1, $e->rows);
            $this->assertSame($raw->id, $e->rows[0]['item_id']);
            $this->assertEqualsWithDelta(3.0, $e->rows[0]['excess'], 0.0001);
            $this->assertEqualsWithDelta(10.0, $e->rows[0]['remaining'], 0.0001);
        }

        // Nothing moved: the voucher is untouched and the stock is where it was.
        $this->assertSame(VoucherStatus::Draft, $voucher->fresh()->status);
        $this->assertEquals(500, $raw->fresh()->quantityIn(WarehouseType::RawMaterials));
    }

    public function test_an_item_that_is_not_on_the_plan_at_all_counts_as_excess(): void
    {
        [$wo] = $this->orderPlanning(planned: 10);
        $stranger = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 2]);
        app(InventoryService::class)->addStock($stranger, 50, null, null, WarehouseType::RawMaterials);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        $voucher->lines()->create(['item_id' => $stranger->id, 'quantity' => 5, 'unit_cost' => 2]);

        $excess = app(IssueVoucherService::class)->excessReport($voucher->fresh());

        $this->assertCount(1, $excess);
        $this->assertSame($stranger->id, $excess[0]['item_id']);
        $this->assertEqualsWithDelta(5.0, $excess[0]['excess'], 0.0001);
    }

    public function test_an_approved_excess_posts_and_is_recorded_on_the_voucher(): void
    {
        [$wo, $raw] = $this->orderPlanning(planned: 10, unitCost: 4);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        $voucher->lines()->first()->update(['quantity' => 13]);

        app(IssueVoucherService::class)->post($voucher->fresh(), allowExcess: true, excessReason: 'تلف قطعة أثناء التجميع');

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Posted, $voucher->status);
        $this->assertTrue($voucher->hasExcess());
        $this->assertSame('تلف قطعة أثناء التجميع', $voucher->excess_reason);
        $this->assertSame($this->actor->id, $voucher->excess_approved_by);
        $this->assertNotNull($voucher->excess_approved_at);

        // And the stock really did move: 13 out of raw, 13 into WIP.
        $this->assertEquals(487, $raw->fresh()->quantityIn(WarehouseType::RawMaterials));
        $this->assertEquals(13, $raw->fresh()->quantityIn(WarehouseType::WorkInProgress));
    }

    public function test_a_voucher_within_the_plan_is_not_flagged(): void
    {
        [$wo] = $this->orderPlanning(planned: 10);

        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);
        app(IssueVoucherService::class)->post($voucher->fresh());

        $this->assertFalse($voucher->fresh()->hasExcess());
        $this->assertNull($voucher->fresh()->excess_approved_by);
    }

    public function test_only_the_excess_permission_may_wave_an_over_issue_through(): void
    {
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');
        $this->assertTrue($warehouse->can('issue_vouchers.approve_excess'));

        // Finance can see vouchers but has no business approving an over-issue.
        $finance = User::factory()->create();
        $finance->assignRole('Finance');
        $this->assertFalse($finance->can('issue_vouchers.approve_excess'));

        [$wo] = $this->orderPlanning(planned: 10);
        $voucher = app(IssueVoucherService::class)->createFromWorkOrder($wo);

        $this->assertTrue($warehouse->can('approveExcess', $voucher));
        $this->assertFalse($finance->can('approveExcess', $voucher));
    }
}
