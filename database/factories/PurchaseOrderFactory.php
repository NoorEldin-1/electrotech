<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'po_number' => 'PO-' . fake()->numerify('######-####'),
            'supplier_name' => fake()->company(),
            'supplier_contact' => fake()->phoneNumber(),
            'status' => fake()->randomElement(PurchaseOrderStatus::cases()),
            'total_amount' => fake()->randomFloat(2, 100, 10000),
            'notes' => fake()->paragraph(),
            'expected_delivery_date' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'created_by' => User::factory(),
        ];
    }

    /**
     * A warehouse/stock purchase order not tied to any operation
     * (project left empty) — مربوط بالمخازن.
     */
    public function warehouse(): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => null,
        ]);
    }
}
