<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MasterData;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization lives here as well as in the controller. Laravel runs
 * `authorize()` before validation, so without it a caller lacking
 * `items.create` would get a 422 spelling out every field and rule of an
 * endpoint they may not use. See API_PROGRESS.md Finding #4.
 */
class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Item::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Unique across soft-deleted rows too: the column carries a plain
            // unique index that ignores `deleted_at`, so reusing a deleted
            // item's SKU would pass validation and then fail at the database
            // with an error the client cannot act on.
            'sku' => ['required', 'string', 'max:100', Rule::unique('items', 'sku')],

            'type' => ['required', Rule::enum(ItemType::class)],
            'unit' => ['required', Rule::enum(UnitOfMeasure::class)],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
