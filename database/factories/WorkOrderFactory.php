<?php

namespace Database\Factories;

use App\Enums\WorkOrderStatus;
use App\Models\Bom;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'bom_id' => Bom::factory(),
            'wo_number' => 'WO-' . fake()->numerify('######-####'),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(WorkOrderStatus::cases()),
            'priority' => fake()->randomElement(['Low', 'Medium', 'High', 'Urgent']),
            'planned_quantity' => fake()->randomFloat(4, 10, 1000),
            'produced_quantity' => 0,
            'waste_quantity' => 0,
            'planned_start_date' => fake()->date(),
            'planned_end_date' => fake()->dateTimeBetween('+1 week', '+1 month')->format('Y-m-d'),
            'assigned_to' => User::factory(),
            'created_by' => User::factory(),
        ];
    }
}
