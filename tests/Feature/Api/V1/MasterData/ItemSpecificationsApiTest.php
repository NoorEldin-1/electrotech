<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\MasterData;

use App\Enums\ItemMaterial;
use App\Models\AdditionVoucher;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Factory-warehouse round: the item API carries the technical
 * specifications, the distinguishing label, the photo and the categories.
 */
class ItemSpecificationsApiTest extends ApiTestCase
{
    public function test_an_item_serializes_its_label_specifications_and_category(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'شرائط نحاس']);
        $item = Item::factory()->create([
            'sku' => 'CU-100*5-EG',
            'name' => 'شريط',
            'item_category_id' => $category->id,
            'part_number' => 'BST 16C',
            'material' => ItemMaterial::Copper,
            'width_mm' => 100,
            'thickness_mm' => 5,
            'size_code' => 'B100',
        ]);

        $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.label', $item->display_label)
            ->assertJsonPath('data.spec_summary', $item->spec_summary)
            ->assertJsonPath('data.category.name', 'شرائط نحاس')
            ->assertJsonPath('data.specifications.part_number', 'BST 16C')
            ->assertJsonPath('data.specifications.material.value', 'cu')
            ->assertJsonPath('data.specifications.size_code', 'B100')
            ->assertJsonPath('data.image_url', null);
    }

    public function test_search_matches_every_word_across_codes_name_and_sizes(): void
    {
        Item::factory()->create(['name' => 'شريط', 'size' => '100*5.75', 'sku' => 'A-1']);
        Item::factory()->create(['name' => 'شريط', 'size' => '60*4', 'sku' => 'A-2']);
        Item::factory()->create(['name' => 'جوان', 'part_number' => 'RG000005', 'sku' => 'A-3']);

        $user = $this->userWith(['items.view']);

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/items?search='.urlencode('شريط 100'))
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.sku', 'A-1');

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/items?search=RG000005')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.sku', 'A-3');
    }

    public function test_it_filters_by_category_material_and_photo(): void
    {
        $category = ItemCategory::factory()->create();
        Item::factory()->create(['sku' => 'IN-CAT', 'item_category_id' => $category->id, 'material' => ItemMaterial::Copper, 'image_path' => 'items/x.jpg']);
        Item::factory()->create(['sku' => 'OUT', 'material' => ItemMaterial::Aluminium]);

        $user = $this->userWith(['items.view']);

        foreach (["filter[category]={$category->id}", 'filter[material]=cu', 'filter[has_image]=true'] as $filter) {
            $this->actingAsApi($user)
                ->apiGet(self::BASE.'/items?'.$filter)
                ->assertOk()
                ->assertJsonPath('meta.pagination.total', 1)
                ->assertJsonPath('data.0.sku', 'IN-CAT');
        }
    }

    public function test_it_creates_an_item_with_specifications_and_a_photo(): void
    {
        Storage::fake('public');
        $category = ItemCategory::factory()->create();

        $response = $this->actingAsApi($this->userWith(['items.view', 'items.create']))
            ->apiUpload(self::BASE.'/items', [
                'name' => 'شريط',
                'sku' => 'CU-NEW',
                'type' => 'raw_material',
                'unit' => 'pcs',
                'unit_cost' => 0,
                'item_category_id' => $category->id,
                'material' => 'cu',
                'width_mm' => 100,
                'thickness_mm' => 5.75,
                'poles' => 4,
                'image' => UploadedFile::fake()->image('bar.jpg', 400, 300),
            ]);

        $response->assertCreated();
        $item = Item::firstWhere('sku', 'CU-NEW');
        $this->assertSame($category->id, $item->item_category_id);
        $this->assertSame(4, $item->poles);
        $this->assertNotNull($item->image_path);
        Storage::disk('public')->assertExists($item->image_path);
        $response->assertJsonPath('data.image_url', $item->image_url);
    }

    public function test_a_patch_can_remove_the_photo_and_rejects_bad_specifications(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('items/old.jpg', 'x');
        $item = Item::factory()->create(['image_path' => 'items/old.jpg']);
        $user = $this->userWith(['items.view', 'items.edit']);

        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/items/'.$item->id, ['remove_image' => true, 'color' => 'اسود'])
            ->assertOk()
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.specifications.color', 'اسود');

        Storage::disk('public')->assertMissing('items/old.jpg');

        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/items/'.$item->id, ['material' => 'gold', 'poles' => 40])
            ->assertUnprocessable();
    }

    public function test_it_lists_categories_with_item_counts(): void
    {
        $first = ItemCategory::factory()->create(['name' => 'شرائط نحاس', 'sort' => 1]);
        ItemCategory::factory()->create(['name' => 'جوانات', 'sort' => 2]);
        Item::factory()->count(2)->create(['item_category_id' => $first->id]);

        $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/item-categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'شرائط نحاس')
            ->assertJsonPath('data.0.items_count', 2)
            ->assertJsonPath('data.1.name', 'جوانات');
    }

    public function test_nested_item_references_carry_the_label(): void
    {
        $voucher = AdditionVoucher::factory()->create();
        $item = Item::factory()->create(['size_code' => 'B160']);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 0]);

        $this->actingAsApi($this->admin())
            ->apiGet(self::BASE.'/addition-vouchers/'.$voucher->id)
            ->assertOk()
            ->assertJsonPath('data.lines.0.item.label', $item->display_label);
    }
}
