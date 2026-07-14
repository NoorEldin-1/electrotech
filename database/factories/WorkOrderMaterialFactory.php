<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Models\Item;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkOrderMaterialFactory extends Factory
{
    protected $model = WorkOrderMaterial::class;

    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'item_id' => Item::factory()->create(['type' => ItemType::RawMaterial, 'unit_cost' => 4]),
            'quantity' => fake()->randomFloat(4, 1, 20),
            'unit_cost' => fake()->randomFloat(2, 1, 50),
            'is_manual' => false,
            'notes' => null,
        ];
    }
}
