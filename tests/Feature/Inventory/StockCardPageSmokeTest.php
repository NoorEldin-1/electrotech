<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemType;
use App\Enums\TransactionType;
use App\Enums\WarehouseType;
use App\Filament\Resources\ItemResource\Pages\ViewItem;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The enhanced item view page (item details + كرت الصنف stock card) renders
 * without error and surfaces the moving-average totals.
 */
class StockCardPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    public function test_view_page_renders_the_stock_card_with_totals(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 999]);

        foreach ([[5, 50, TransactionType::In], [1, 80, TransactionType::In], [4, null, TransactionType::Out]] as [$q, $c, $t]) {
            InventoryTransaction::factory()->create([
                'item_id' => $item->id,
                'type' => $t,
                'warehouse_type' => WarehouseType::RawMaterials,
                'quantity' => $q,
                'unit_cost' => $c,
                'performed_by' => $this->admin->id,
            ]);
        }

        Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
            ->assertOk()
            ->assertSee(__('resources.stock_card.heading'))
            ->assertSee(__('resources.stock_card.groups.balance'))
            // Final balance value: 2 @ 55 = 110.00
            ->assertSee('110.00');
    }

    public function test_view_page_renders_with_empty_ledger(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial]);

        Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
            ->assertOk()
            ->assertSee(__('resources.stock_card.empty'));
    }

    public function test_warehouse_selector_switches_the_card(): void
    {
        $item = Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 999]);

        // raw_materials: 5 @ 50 = 250 value
        InventoryTransaction::factory()->create([
            'item_id' => $item->id, 'type' => TransactionType::In,
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => 5, 'unit_cost' => 50, 'performed_by' => $this->admin->id,
        ]);
        // work_in_progress: 3 @ 20 = 60 value
        InventoryTransaction::factory()->create([
            'item_id' => $item->id, 'type' => TransactionType::In,
            'warehouse_type' => WarehouseType::WorkInProgress,
            'quantity' => 3, 'unit_cost' => 20, 'performed_by' => $this->admin->id,
        ]);

        // Defaults to the home (raw_materials) warehouse → shows its 250.00 total.
        Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
            ->assertSet('stockCardWarehouse', WarehouseType::RawMaterials->value)
            ->assertSee('250.00')
            // Switching to WIP recomputes the card → 60.00 total appears.
            ->set('stockCardWarehouse', WarehouseType::WorkInProgress->value)
            ->assertSee('60.00');
    }
}
