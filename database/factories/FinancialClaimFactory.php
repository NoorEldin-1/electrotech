<?php

namespace Database\Factories;

use App\Enums\ClaimStatus;
use App\Models\FinancialClaim;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinancialClaimFactory extends Factory
{
    protected $model = FinancialClaim::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'claim_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 1000, 500000),
            'status' => ClaimStatus::Draft,
            'description' => fake()->sentence(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => ClaimStatus::Submitted, 'submitted_at' => now()]);
    }

    public function collected(): static
    {
        return $this->state(fn () => [
            'status' => ClaimStatus::Collected,
            'submitted_at' => now(),
            'collected_at' => now(),
        ]);
    }
}
