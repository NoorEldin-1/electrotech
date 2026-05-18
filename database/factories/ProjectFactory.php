<?php

namespace Database\Factories;

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
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(ProjectStatus::cases()),
            'estimated_budget' => fake()->randomFloat(2, 10000, 1000000),
            'actual_cost' => fake()->randomFloat(2, 5000, 900000),
            'start_date' => fake()->date(),
            'end_date' => fake()->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'created_by' => User::factory(),
        ];
    }
}
