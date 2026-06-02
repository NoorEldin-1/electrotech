<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SiteSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteSurveyFactory extends Factory
{
    protected $model = SiteSurvey::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'survey_date' => now()->toDateString(),
            'measurements' => fake()->sentence(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
