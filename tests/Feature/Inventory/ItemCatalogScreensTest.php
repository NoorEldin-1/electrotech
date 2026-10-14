<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemMaterial;
use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Enums\WarehouseType;
use App\Filament\Resources\ItemCategoryResource\Pages\ManageItemCategories;
use App\Filament\Resources\ItemResource\Pages\CreateItem;
use App\Filament\Resources\ItemResource\Pages\ListItems;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The item screens carry the factory-warehouse specifications: the form
 * stores them, the list searches and filters by them, and categories are
 * managed from their own page.
 */
class ItemCatalogScreensTest extends TestCase
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

    public function test_item_form_saves_specifications_and_category(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'شرائط نحاس']);

        Livewire::test(CreateItem::class)
            ->fillForm([
                'sku' => 'CU-100*5-EG',
                'name' => 'شريط',
                'type' => ItemType::RawMaterial->value,
                'unit' => UnitOfMeasure::Piece->value,
                'item_category_id' => $category->id,
                'part_number' => 'BST 16C',
                'material' => ItemMaterial::Copper->value,
                'thickness_mm' => 5,
                'width_mm' => 100,
                'length_m' => 3,
                'ampere_rating' => '1600',
                'poles' => 4,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Item::firstWhere('sku', 'CU-100*5-EG');
        $this->assertTrue($item->category->is($category));
        $this->assertSame(ItemMaterial::Copper, $item->material);
        $this->assertSame('BST 16C', $item->part_number);
        $this->assertSame(4, $item->poles);
    }

    public function test_list_search_finds_items_by_name_and_size_together(): void
    {
        $wanted = Item::factory()->create(['name' => 'شريط', 'size' => '100*5.75']);
        $other = Item::factory()->create(['name' => 'شريط', 'size' => '60*4']);

        Livewire::test(ListItems::class)
            ->searchTable('شريط 100')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_list_filters_by_category_and_zero_stock(): void
    {
        $category = ItemCategory::factory()->create();
        $inCategory = Item::factory()->create(['item_category_id' => $category->id]);
        $stocked = Item::factory()->create();
        Inventory::create([
            'item_id' => $stocked->id,
            'warehouse_type' => WarehouseType::RawMaterials,
            'on_hand_quantity' => 5,
            'on_hold_quantity' => 0,
        ]);

        Livewire::test(ListItems::class)
            ->filterTable('item_category_id', [$category->id])
            ->assertCanSeeTableRecords([$inCategory])
            ->assertCanNotSeeTableRecords([$stocked]);

        Livewire::test(ListItems::class)
            ->filterTable('zero_stock')
            ->assertCanSeeTableRecords([$inCategory])
            ->assertCanNotSeeTableRecords([$stocked]);
    }

    public function test_categories_page_lists_categories_with_item_counts(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'جوانات']);
        Item::factory()->count(2)->create(['item_category_id' => $category->id]);

        Livewire::test(ManageItemCategories::class)
            ->assertCanSeeTableRecords([$category])
            ->assertSee('جوانات');
    }

    public function test_quick_view_shows_specifications(): void
    {
        $item = Item::factory()->create(['brand' => 'Zucchini', 'size_code' => 'B160']);

        $html = view('filament.items.quick-view', ['item' => $item->load('inventories')])->render();

        $this->assertStringContainsString('Zucchini', $html);
        $this->assertStringContainsString('B160', $html);
    }
}
