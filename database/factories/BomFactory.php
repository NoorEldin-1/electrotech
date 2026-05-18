<?php

namespace Database\Factories;

use App\Enums\BomStatus;
use App\Models\Bom;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BomFactory extends Factory
{
    protected $model = Bom::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => fake()->numberBetween(1, 5),
            'status' => fake()->randomElement(BomStatus::cases()),
            'notes' => fake()->sentence(),
            'prepared_by' => User::factory(),
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ];
    }
}
