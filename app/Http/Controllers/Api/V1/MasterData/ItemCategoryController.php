<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ItemCategory;
use Illuminate\Http\JsonResponse;

/**
 * @group 7. Items
 */
class ItemCategoryController extends ApiController
{
    /**
     * List item categories
     *
     * The catalog groups (فئات الأصناف) items are filed under, in display
     * order, with how many items each holds. A short, unpaginated list meant
     * for filter chips and the item form's category picker; filter the item
     * list with `filter[category]={id}`.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"type":"item_category","name":"شرائط نحاس","sort":1,"items_count":49}],"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ItemCategory::class);

        $categories = ItemCategory::query()
            ->withCount('items')
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->map(fn (ItemCategory $category): array => [
                'id' => $category->id,
                'type' => 'item_category',
                'name' => $category->name,
                'sort' => $category->sort,
                'items_count' => $category->items_count,
            ]);

        return $this->respond($categories->all());
    }
}
