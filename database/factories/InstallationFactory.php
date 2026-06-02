<?php

namespace Database\Factories;

use App\Enums\InstallationStatus;
use App\Models\Installation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstallationFactory extends Factory
{
    protected $model = Installation::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'status' => InstallationStatus::Pending,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => InstallationStatus::InProgress, 'started_at' => now()]);
    }
}
