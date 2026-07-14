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
        $plannedCost = fake()->randomFloat(2, 1000, 5000);

        return [
            'work_order_id' => WorkOrder::factory(),
            'output_item_id' => null,
            'operation_name' => fake()->sentence(3),
            'entry_date' => now()->format('Y-m-d'),
            'planned_quantity' => fake()->randomFloat(4, 10, 100),
            'produced_quantity' => fake()->randomFloat(4, 10, 100),
            'scrap_quantity' => fake()->randomFloat(4, 0, 10),
            'planned_material_cost' => $plannedCost,
            'actual_material_cost' => $plannedCost + fake()->randomFloat(2, 0, 500),
        ];
    }
}
