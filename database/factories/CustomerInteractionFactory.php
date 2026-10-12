<?php

namespace Database\Factories;

use App\Enums\InteractionChannel;
use App\Enums\InteractionType;
use App\Models\CustomerInteraction;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerInteractionFactory extends Factory
{
    protected $model = CustomerInteraction::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => InteractionType::Contact,
            'channel' => InteractionChannel::Call,
            'interacted_at' => now(),
            'site_ready' => false,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function siteReady(): static
    {
        return $this->state(fn () => ['type' => InteractionType::SiteReadiness, 'site_ready' => true]);
    }
}
