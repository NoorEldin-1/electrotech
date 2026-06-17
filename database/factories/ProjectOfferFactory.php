<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectOfferFactory extends Factory
{
    protected $model = ProjectOffer::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => 1,
            'financial_amount' => fake()->randomFloat(2, 5000, 500000),
            'submitted_at' => now(),
            'submitted_by' => User::factory(),
            'notes' => fake()->optional()->sentence(),
            'is_winning' => false,
        ];
    }
}
