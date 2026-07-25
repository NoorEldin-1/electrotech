<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AccountDirection;
use App\Enums\DeliveryVoucherStatus;
use App\Enums\ItemType;
use App\Enums\WarehouseType;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\DeliveryVoucherLine;
use App\Models\Item;
use App\Models\User;
use App\Services\DeliveryVoucherService;
use App\Services\InventoryService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryVoucherApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array{0: DeliveryVoucher, 1: Item}
     */
    private function makeVoucherWithStock(float $stock = 20): array
    {
        $customer = Customer::factory()->create();
        $finished = Item::factory()->create(['type' => ItemType::FinishedGood]);
        app(InventoryService::class)->addStock($finished, $stock, null, null, WarehouseType::FinishedGoods);

        $voucher = DeliveryVoucher::factory()->create([
            'customer_id' => $customer->id,
            'status' => DeliveryVoucherStatus::Draft,
        ]);
        DeliveryVoucherLine::create([
            'delivery_voucher_id' => $voucher->id,
            'item_id' => $finished->id,
            'quantity' => 5,
            'unit_cost' => 100,
        ]);

        return [$voucher->fresh('lines'), $finished];
    }

    public function test_single_approval_does_not_move_stock_or_post(): void
    {
        [$voucher, $finished] = $this->makeVoucherWithStock();
        $approver = User::factory()->create();

        app(DeliveryVoucherService::class)->approveTechnical($voucher, $approver);

        $voucher->refresh();
        $this->assertSame(DeliveryVoucherStatus::PendingApproval, $voucher->status);
        $this->assertEquals(20, $finished->fresh()->quantityIn(WarehouseType::FinishedGoods)); // untouched
        $this->assertSame(0, AccountEntry::count());
    }

    public function test_dual_approval_activates_deducts_stock_and_debits_customer(): void
    {
        [$voucher, $finished] = $this->makeVoucherWithStock(20);
        $technical = User::factory()->create();
        $financial = User::factory()->create();

        $service = app(DeliveryVoucherService::class);
        $service->approveTechnical($voucher, $technical);
        $service->approveFinancial($voucher->fresh(), $financial);

        $voucher->refresh();
        $this->assertSame(DeliveryVoucherStatus::Active, $voucher->status);
        $this->assertNotNull($voucher->activated_at);
        $this->assertEquals(500, (float) $voucher->total_value); // 5 * 100

        // Finished goods deducted.
        $this->assertEquals(15, $finished->fresh()->quantityIn(WarehouseType::FinishedGoods));

        // Customer debited.
        $entry = AccountEntry::first();
        $this->assertNotNull($entry);
        $this->assertSame(AccountDirection::Debit, $entry->direction);
        $this->assertEquals(500, (float) $entry->amount);
        $this->assertEquals(500, $voucher->customer->fresh()->balance);
    }

    public function test_activation_fails_when_finished_stock_is_short(): void
    {
        [$voucher] = $this->makeVoucherWithStock(2); // only 2 in stock, need 5
        $service = app(DeliveryVoucherService::class);
        $service->approveTechnical($voucher, User::factory()->create());

        $this->expectException(\RuntimeException::class);
        $service->approveFinancial($voucher->fresh(), User::factory()->create());
    }

    public function test_a_failed_activation_rolls_the_signature_back(): void
    {
        // Signature + activation are one unit of work: a voucher that cannot
        // be delivered must not keep a financial approval it never earned.
        [$voucher, $finished] = $this->makeVoucherWithStock(2); // need 5
        $service = app(DeliveryVoucherService::class);
        $service->approveTechnical($voucher, User::factory()->create());

        try {
            $service->approveFinancial($voucher->fresh(), User::factory()->create());
            $this->fail('Activation should have failed on insufficient stock.');
        } catch (\RuntimeException) {
            // expected
        }

        $voucher->refresh();
        $this->assertNull($voucher->financial_approved_by, 'The signature must not survive a failed activation.');
        $this->assertNull($voucher->financial_approved_at);
        $this->assertSame(DeliveryVoucherStatus::PendingApproval, $voucher->status);
        $this->assertNull($voucher->activated_at);
        $this->assertEquals(2, $finished->fresh()->quantityIn(WarehouseType::FinishedGoods));
        $this->assertSame(0, AccountEntry::count());
    }

    public function test_rbac_separates_technical_and_financial_approval(): void
    {
        $factory = User::factory()->create();
        $factory->assignRole('Factory_Manager');
        $this->assertTrue($factory->can('delivery_vouchers.approve_technical'));
        $this->assertFalse($factory->can('delivery_vouchers.approve_financial'));

        $finance = User::factory()->create();
        $finance->assignRole('Finance');
        $this->assertTrue($finance->can('delivery_vouchers.approve_financial'));
        $this->assertFalse($finance->can('delivery_vouchers.approve_technical'));

        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');
        $this->assertTrue($warehouse->can('delivery_vouchers.create'));
        $this->assertFalse($warehouse->can('delivery_vouchers.approve_financial'));
    }
}
