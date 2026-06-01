<?php

namespace Database\Factories;

use App\Models\ProductionEntry;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionEntryFactory extends Factory
{
    protected $model = ProductionEntry::class;

    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'output_item_id' => null,
            'entry_date' => now()->format('Y-m-d'),
            'planned_quantity' => fake()->randomFloat(4, 10, 100),
            'produced_quantity' => fake()->randomFloat(4, 10, 100),
            'scrap_quantity' => fake()->randomFloat(4, 0, 10),
        ];
    }
}
