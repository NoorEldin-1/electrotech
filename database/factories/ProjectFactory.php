<?php

namespace Database\Factories;

use App\Enums\ArrivalMethod;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Project',
            'code' => 'PRJ-' . fake()->numerify('######-####'),
            'client_name' => fake()->company(),
            'consultant_name' => fake()->name(),
            'engineer_name' => fake()->name(),
            'electric_current' => fake()->randomElement(['16A', '32A', '63A', '100A', '160A']),
            'model' => fake()->bothify('M-####'),
            'section_type' => fake()->randomElement(['Main', 'Sub', 'Distribution']),
            'poles_count' => fake()->randomElement([1, 2, 3, 4]),
            'quantity' => fake()->numberBetween(1, 50),
            'project_location' => fake()->city(),
            'arrival_method' => fake()->randomElement(ArrivalMethod::cases())->value,
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(ProjectStatus::cases()),
            'estimated_budget' => fake()->randomFloat(2, 10000, 1000000),
            'actual_cost' => fake()->randomFloat(2, 5000, 900000),
            'start_date' => fake()->date(),
            'end_date' => fake()->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'created_by' => User::factory(),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn () => ['status' => ProjectStatus::Draft]);
    }

    public function tender(): self
    {
        return $this->state(fn () => ['status' => ProjectStatus::Tender]);
    }

    public function inHand(): self
    {
        return $this->state(fn () => [
            'status' => ProjectStatus::InHand,
            'smb_status' => 'pending',
        ]);
    }

    /** Active operation (status = InProgress) — the live cost-center stage. */
    public function active(): self
    {
        return $this->state(fn () => ['status' => ProjectStatus::InProgress]);
    }

    public function completed(): self
    {
        return $this->state(fn () => ['status' => ProjectStatus::Completed]);
    }
}
