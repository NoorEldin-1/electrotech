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
            // Default to a manufacturing-ready (approved) state so existing
            // flows work without threading the new Draft gate. Use draft()
            // to exercise the PMO approval step (سلايد 5).
            'status' => WorkOrderStatus::Pending,
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

    /**
     * A freshly authored order awaiting PMO-manager approval (سلايد 5).
     */
    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => WorkOrderStatus::Draft,
            'order_approved_by' => null,
            'order_approved_at' => null,
        ]);
    }

    /**
     * An order carrying BOTH approvals — the PMO release and the QA sign-off —
     * which is what "انتهاء التصنيع" now requires before it will fire.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'order_approved_by' => User::factory(),
            'order_approved_at' => now(),
            'qa_approved_by' => User::factory(),
            'qa_approved_at' => now(),
        ]);
    }
}
