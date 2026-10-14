<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MasterData\Concerns;

use App\Enums\ItemEdge;
use App\Enums\ItemMaterial;
use App\Enums\ItemOrigin;
use App\Enums\ItemTreatment;
use App\Enums\ProductLine;
use Illuminate\Validation\Rule;

/**
 * Validation for an item's technical specifications and photo, shared by the
 * create and update requests. All optional; the update request prefixes each
 * with `sometimes` so a PATCH only validates what it sends.
 */
trait ItemSpecificationRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function specificationRules(): array
    {
        return [
            'item_category_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')],
            'product_line' => ['nullable', Rule::enum(ProductLine::class)],
            'part_number' => ['nullable', 'string', 'max:100'],
            'material' => ['nullable', Rule::enum(ItemMaterial::class)],
            'thickness_mm' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'width_mm' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'size_code' => ['nullable', 'string', 'max:20'],
            'length_m' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'edge' => ['nullable', Rule::enum(ItemEdge::class)],
            'treatment' => ['nullable', Rule::enum(ItemTreatment::class)],
            'origin' => ['nullable', Rule::enum(ItemOrigin::class)],
            'brand' => ['nullable', 'string', 'max:100'],
            'usage' => ['nullable', 'string', 'max:100'],
            'ampere_rating' => ['nullable', 'string', 'max:30'],
            'poles' => ['nullable', 'integer', 'min:1', 'max:10'],
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:30'],

            // The photo travels as a multipart file; `remove_image` clears it.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }
}
