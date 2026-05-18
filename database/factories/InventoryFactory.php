<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_type' => fake()->randomElement(['Main', 'Secondary', 'Quarantine']),
            'on_hand_quantity' => fake()->randomFloat(4, 0, 1000),
            'on_hold_quantity' => 0,
        ];
    }
}
