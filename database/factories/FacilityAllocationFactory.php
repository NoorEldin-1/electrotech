<?php

namespace Database\Factories;

use App\Models\CreditFacility;
use App\Models\FacilityAllocation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class FacilityAllocationFactory extends Factory
{
    protected $model = FacilityAllocation::class;

    public function definition(): array
    {
        return [
            'credit_facility_id' => CreditFacility::factory(),
            'project_id' => Project::factory(),
            'allocated_amount' => fake()->randomFloat(2, 10000, 100000),
            'allocated_at' => now()->toDateString(),
            'status' => 'active',
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => ['status' => 'released']);
    }
}
