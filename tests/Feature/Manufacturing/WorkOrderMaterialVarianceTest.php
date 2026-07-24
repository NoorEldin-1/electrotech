<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\ItemType;
use App\Enums\VoucherStatus;
use App\Models\IssueVoucher;
use App\Models\Item;
use App\Models\ReturnVoucher;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderMaterialVarianceService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مقارنة أمر التشغيل بأوامر الصرف (قائمة المواد سلايد 1): five chairs need
 * 15 kg of wood; issuing 16 kg leaves a 1 kg loss.
 */
class WorkOrderMaterialVarianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function wood(float $unitCost = 10): Item
    {
        return Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => $unitCost]);
    }

    private function issue(WorkOrder $wo, Item $item, float $qty, float $unitCost = 10, VoucherStatus $status = VoucherStatus::Posted): void
    {
        $voucher = IssueVoucher::factory()->create(['work_order_id' => $wo->id, 'status' => $status]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $unitCost]);
    }

    private function returnScrap(WorkOrder $wo, Item $item, float $qty, float $unitCost = 10): void
    {
        $voucher = ReturnVoucher::factory()->create(['work_order_id' => $wo->id, 'status' => VoucherStatus::Posted]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $unitCost]);
    }

    public function test_issuing_more_than_planned_reads_as_a_loss(): void
    {
        $wo = WorkOrder::factory()->create(['planned_quantity' => 5]);
        $wood = $this->wood();
        $wo->materials()->create(['item_id' => $wood->id, 'quantity' => 15, 'unit_cost' => 10]);

        $this->issue($wo, $wood, 16);

        $variance = app(WorkOrderMaterialVarianceService::class)->for($wo);
        $row = $variance['rows']->first();

        $this->assertSame(15.0, $row['planned']);
        $this->assertSame(16.0, $row['net_issued']);
        $this->assertSame(1.0, $row['variance']);
        $this->assertSame(10.0, $row['variance_value']);
        $this->assertSame(10.0, $variance['variance_value']);
    }

    public function test_returned_material_is_netted_off_the_issued_quantity(): void
    {
        $wo = WorkOrder::factory()->create(['planned_quantity' => 5]);
        $wood = $this->wood();
        $wo->materials()->create(['item_id' => $wood->id, 'quantity' => 15, 'unit_cost' => 10]);

        $this->issue($wo, $wood, 18);
        $this->returnScrap($wo, $wood, 3);

        $row = app(WorkOrderMaterialVarianceService::class)->for($wo)['rows']->first();

        $this->assertSame(18.0, $row['issued']);
        $this->assertSame(3.0, $row['returned']);
        $this->assertSame(15.0, $row['net_issued']);
        $this->assertSame(0.0, $row['variance']);
    }

    public function test_draft_issue_vouchers_are_ignored(): void
    {
        $wo = WorkOrder::factory()->create(['planned_quantity' => 5]);
        $wood = $this->wood();
        $wo->materials()->create(['item_id' => $wood->id, 'quantity' => 15, 'unit_cost' => 10]);

        $this->issue($wo, $wood, 16, 10, VoucherStatus::Draft);

        $row = app(WorkOrderMaterialVarianceService::class)->for($wo)['rows']->first();

        $this->assertSame(0.0, $row['issued']);
        $this->assertSame(-15.0, $row['variance']);
    }

    public function test_comparison_prints_as_pdf(): void
    {
        $wo = WorkOrder::factory()->create(['planned_quantity' => 5]);
        $wood = $this->wood();
        $wo->materials()->create(['item_id' => $wood->id, 'quantity' => 15, 'unit_cost' => 10]);
        $this->issue($wo, $wood, 16);

        $user = User::factory()->create();
        $user->assignRole('Technical_Office');
        $this->actingAs($user);

        $this->get(route('work_orders.material_variance.pdf', ['workOrder' => $wo->getKey()]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_material_issued_without_being_planned_still_appears(): void
    {
        $wo = WorkOrder::factory()->create(['planned_quantity' => 5]);
        $nails = $this->wood(2);

        $this->issue($wo, $nails, 50, 2);

        $row = app(WorkOrderMaterialVarianceService::class)->for($wo)['rows']->first();

        $this->assertSame(0.0, $row['planned']);
        $this->assertSame(50.0, $row['variance']);
        $this->assertSame(100.0, $row['variance_value']);
        // No plan to compare against — percentage is undefined, not zero.
        $this->assertNull($row['variance_percentage']);
    }
}
