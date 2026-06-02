<?php

namespace Database\Factories;

use App\Models\DeliveryMinute;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryMinuteFactory extends Factory
{
    protected $model = DeliveryMinute::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'minute_date' => now()->toDateString(),
            'content' => fake()->sentence(),
        ];
    }

    public function distributed(): static
    {
        return $this->state(fn () => ['distributed_at' => now()]);
    }
}
