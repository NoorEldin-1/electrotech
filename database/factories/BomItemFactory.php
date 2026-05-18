<?php

namespace Database\Factories;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

class BomItemFactory extends Factory
{
    protected $model = BomItem::class;

    public function definition(): array
    {
        return [
            'bom_id' => Bom::factory(),
            'item_id' => Item::factory(),
            'quantity' => fake()->randomFloat(4, 1, 100),
            'waste_percentage' => fake()->randomFloat(2, 0, 10),
            'notes' => fake()->sentence(),
        ];
    }
}
