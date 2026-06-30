<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemType;
use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Models\AdditionVoucher;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\User;
use App\Services\StockCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the moving weighted-average stock card (كرت الصنف) reproduces the
 * manual accounting card from المشتريات.pptx exactly, plus the edge cases the
 * slide doesn't cover.
 */
class StockCardTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
    }

    private function rawItem(): Item
    {
        // unit_cost is the static master cost; the card must ignore it and use
        // each movement's own unit_cost instead.
        return Item::factory()->create([
            'type' => ItemType::RawMaterial,
            'unit_cost' => 999,
        ]);
    }

    private function move(Item $item, TransactionType $type, float $qty, ?float $unitCost, string $when): void
    {
        InventoryTransaction::factory()->create([
            'item_id' => $item->id,
            'type' => $type,
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'performed_by' => $this->actor->id,
            'created_at' => $when,
        ]);
    }

    /**
     * The canonical slide-7 scenario:
     *   opening 5 @ 50           → balance 5  @ 50    = 250
     *   +1 @ 80                  → balance 6  @ 55    = 330   (330 / 6  = 55)
     *   −4 (issued @ 55)         → balance 2  @ 55    = 110
     *   +10 @ 70                 → balance 12 @ 67.5  = 810   (810 / 12 = 67.5)
     *   −7 (issued @ 67.5)       → balance 5  @ 67.5  = 337.5
     */
    public function test_reproduces_the_powerpoint_moving_average_card(): void
    {
        $item = $this->rawItem();

        $this->move($item, TransactionType::In, 5, 50, '2026-01-01 09:00:00');   // أول المدة
        $this->move($item, TransactionType::In, 1, 80, '2026-01-05 09:00:00');   // إذن إضافة 1
        $this->move($item, TransactionType::Out, 4, null, '2026-01-30 09:00:00'); // إذن صرف 1
        $this->move($item, TransactionType::In, 10, 70, '2026-02-09 09:00:00');  // إذن إضافة 2
        $this->move($item, TransactionType::Out, 7, null, '2026-03-03 09:00:00'); // إذن صرف 2

        $card = app(StockCardService::class)->build($item, WarehouseType::RawMaterials);
        $rows = $card['rows'];

        $this->assertCount(5, $rows);

        // Row 1 — opening 5 @ 50
        $this->assertEqualsWithDelta(250, $rows[0]['balance_value'], 0.001);
        $this->assertEqualsWithDelta(50, $rows[0]['balance_price'], 0.001);

        // Row 2 — +1 @ 80 → average becomes 55
        $this->assertEqualsWithDelta(6, $rows[1]['balance_qty'], 0.001);
        $this->assertEqualsWithDelta(55, $rows[1]['balance_price'], 0.001);
        $this->assertEqualsWithDelta(330, $rows[1]['balance_value'], 0.001);

        // Row 3 — −4 issued at the 55 average
        $this->assertEqualsWithDelta(55, $rows[2]['out_price'], 0.001);
        $this->assertEqualsWithDelta(220, $rows[2]['out_value'], 0.001);
        $this->assertEqualsWithDelta(2, $rows[2]['balance_qty'], 0.001);
        $this->assertEqualsWithDelta(110, $rows[2]['balance_value'], 0.001);

        // Row 4 — +10 @ 70 → average becomes 67.5
        $this->assertEqualsWithDelta(12, $rows[3]['balance_qty'], 0.001);
        $this->assertEqualsWithDelta(67.5, $rows[3]['balance_price'], 0.001);
        $this->assertEqualsWithDelta(810, $rows[3]['balance_value'], 0.001);

        // Row 5 — −7 issued at 67.5 → final balance 5 @ 67.5 = 337.5
        $this->assertEqualsWithDelta(67.5, $rows[4]['out_price'], 0.001);
        $this->assertEqualsWithDelta(472.5, $rows[4]['out_value'], 0.001);
        $this->assertEqualsWithDelta(5, $rows[4]['balance_qty'], 0.001);
        $this->assertEqualsWithDelta(337.5, $rows[4]['balance_value'], 0.001);

        // الإجمالي = last balance row
        $this->assertEqualsWithDelta(5, $card['totals']['quantity'], 0.001);
        $this->assertEqualsWithDelta(67.5, $card['totals']['unit_cost'], 0.001);
        $this->assertEqualsWithDelta(337.5, $card['totals']['value'], 0.001);
    }

    public function test_empty_card_has_no_rows_and_zero_totals(): void
    {
        $card = app(StockCardService::class)->build($this->rawItem(), WarehouseType::RawMaterials);

        $this->assertSame([], $card['rows']);
        $this->assertEqualsWithDelta(0, $card['totals']['quantity'], 0.001);
        $this->assertEqualsWithDelta(0, $card['totals']['value'], 0.001);
    }

    public function test_balance_reset_to_zero_then_re_added_starts_a_fresh_average(): void
    {
        $item = $this->rawItem();

        $this->move($item, TransactionType::In, 5, 50, '2026-01-01 09:00:00');
        $this->move($item, TransactionType::Out, 5, null, '2026-01-02 09:00:00'); // fully issued → 0
        $this->move($item, TransactionType::In, 2, 90, '2026-01-03 09:00:00');     // brand-new batch

        $card = app(StockCardService::class)->build($item, WarehouseType::RawMaterials);

        // After full issue the pool is empty — no stale value drags the average.
        $this->assertEqualsWithDelta(0, $card['rows'][1]['balance_qty'], 0.001);
        $this->assertEqualsWithDelta(0, $card['rows'][1]['balance_value'], 0.001);

        // The fresh batch sets the average to its own price, not a blend.
        $this->assertEqualsWithDelta(90, $card['rows'][2]['balance_price'], 0.001);
        $this->assertEqualsWithDelta(180, $card['totals']['value'], 0.001);
    }

    public function test_hold_and_release_movements_are_excluded_from_the_value_card(): void
    {
        $item = $this->rawItem();

        $this->move($item, TransactionType::In, 5, 50, '2026-01-01 09:00:00');
        $this->move($item, TransactionType::Hold, 3, null, '2026-01-02 09:00:00');
        $this->move($item, TransactionType::Release, 3, null, '2026-01-03 09:00:00');

        $card = app(StockCardService::class)->build($item, WarehouseType::RawMaterials);

        // Only the single In movement is on the card; reservations don't appear.
        $this->assertCount(1, $card['rows']);
        $this->assertEqualsWithDelta(250, $card['totals']['value'], 0.001);
    }

    public function test_only_the_requested_warehouse_is_considered(): void
    {
        $item = $this->rawItem();

        $this->move($item, TransactionType::In, 5, 50, '2026-01-01 09:00:00'); // raw_materials
        InventoryTransaction::factory()->create([
            'item_id' => $item->id,
            'type' => TransactionType::In,
            'warehouse_type' => WarehouseType::WorkInProgress,
            'quantity' => 100,
            'unit_cost' => 12,
            'performed_by' => $this->actor->id,
        ]);

        $card = app(StockCardService::class)->build($item, WarehouseType::RawMaterials);

        $this->assertCount(1, $card['rows']);
        $this->assertEqualsWithDelta(5, $card['totals']['quantity'], 0.001);
    }

    public function test_defaults_to_the_items_home_warehouse(): void
    {
        $item = $this->rawItem(); // RawMaterial → home is raw_materials
        $this->move($item, TransactionType::In, 5, 50, '2026-01-01 09:00:00');

        $card = app(StockCardService::class)->build($item); // no warehouse passed

        $this->assertSame(WarehouseType::RawMaterials, $card['warehouse']);
        $this->assertEqualsWithDelta(250, $card['totals']['value'], 0.001);
    }

    public function test_statement_resolves_the_source_document_label(): void
    {
        $item = $this->rawItem();
        $voucher = AdditionVoucher::factory()->create(['voucher_number' => 'AV-202606-0004']);

        InventoryTransaction::factory()->create([
            'item_id' => $item->id,
            'type' => TransactionType::In,
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => 5,
            'unit_cost' => 50,
            'performed_by' => $this->actor->id,
            'reference_type' => $voucher->getMorphClass(),
            'reference_id' => $voucher->getKey(),
        ]);

        $card = app(StockCardService::class)->build($item, WarehouseType::RawMaterials);

        $this->assertSame(
            __('resources.stock_card.documents.AdditionVoucher') . ' AV-202606-0004',
            $card['rows'][0]['reference'],
        );
    }
}
