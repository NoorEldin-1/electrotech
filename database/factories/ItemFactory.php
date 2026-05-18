<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word() . ' ' . fake()->word(),
            'sku' => fake()->unique()->bothify('SKU-####-????'),
            'type' => fake()->randomElement(ItemType::cases()),
            'unit' => fake()->randomElement(UnitOfMeasure::cases()),
            'unit_cost' => fake()->randomFloat(2, 1, 1000),
            'description' => fake()->sentence(),
            'minimum_stock' => fake()->randomFloat(4, 10, 100),
        ];
    }
}
