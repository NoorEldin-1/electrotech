<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ItemMaterial;
use App\Enums\ItemTreatment;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Factory-warehouse import round: items carry technical specifications and a
 * distinguishing label, because the catalog has dozens of items sharing one
 * generic name («شريط»).
 */
class ItemSpecificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
    }

    public function test_spec_summary_lists_only_the_filled_specifications(): void
    {
        $item = Item::factory()->make([
            'material' => ItemMaterial::Copper,
            'width_mm' => 100,
            'thickness_mm' => 5.75,
            'length_m' => 3,
            'treatment' => ItemTreatment::Tinned,
            'color' => 'اسود',
        ]);

        $this->assertSame('Cu · 100×5.75 مم · 3م · مقصدر · اسود', $item->spec_summary);
    }

    public function test_untreated_items_and_size_codes_read_cleanly(): void
    {
        $item = Item::factory()->make([
            'material' => ItemMaterial::Aluminium,
            'size_code' => 'B100',
            'treatment' => ItemTreatment::None,
            'ampere_rating' => '1600',
            'poles' => 4,
        ]);

        $this->assertSame('Al · B100 · 1600A · 4P', $item->spec_summary);
    }

    public function test_free_text_size_is_used_when_there_are_no_dimensions(): void
    {
        $item = Item::factory()->make(['size' => '95mm2']);

        $this->assertSame('95mm2', $item->spec_summary);
    }

    public function test_display_label_tells_apart_items_with_the_same_name(): void
    {
        $a = Item::factory()->make(['sku' => 'CU-100*5-EG', 'name' => 'شريط', 'width_mm' => 100, 'thickness_mm' => 5]);
        $b = Item::factory()->make(['sku' => 'CU-100*6-EG', 'name' => 'شريط', 'width_mm' => 100, 'thickness_mm' => 6]);

        $this->assertSame('CU-100*5-EG — شريط (100×5 مم)', $a->display_label);
        $this->assertNotSame($a->display_label, $b->display_label);
    }

    public function test_items_without_specifications_keep_a_plain_label(): void
    {
        $item = Item::factory()->make(['sku' => 'X-1', 'name' => 'Legacy item']);

        $this->assertSame('', $item->spec_summary);
        $this->assertSame('X-1 — Legacy item', $item->display_label);
        $this->assertNull($item->image_url);
    }

    public function test_search_matches_every_word_across_codes_name_and_sizes(): void
    {
        $match = Item::factory()->create(['name' => 'شريط', 'part_number' => 'BST 16C', 'size' => '100*5.75']);
        Item::factory()->create(['name' => 'شريط', 'part_number' => 'X', 'size' => '60*4']);
        Item::factory()->create(['name' => 'جوان', 'size' => '100*5.75']);

        $this->assertSame([$match->id], Item::search('شريط 100*5')->pluck('id')->all());
        $this->assertSame([$match->id], Item::search('bst 16c')->pluck('id')->all());
        $this->assertSame(3, Item::search('')->count());
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        Item::factory()->create(['name' => 'abc']);

        $this->assertSame(0, Item::search('%')->count());
        $this->assertSame(0, Item::search('_')->count());
    }

    public function test_category_groups_items_and_guards_deletion(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->givePermissionTo(['items.view', 'items.delete']);

        $category = ItemCategory::factory()->create(['name' => 'شرائط نحاس']);
        $empty = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $this->assertTrue($item->category->is($category));
        $this->assertFalse($admin->can('delete', $category));
        $this->assertTrue($admin->can('delete', $empty));
    }
}
