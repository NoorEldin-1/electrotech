<?php

namespace Database\Factories;

use App\Enums\FacilityStatus;
use App\Models\CreditFacility;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreditFacilityFactory extends Factory
{
    protected $model = CreditFacility::class;

    public function definition(): array
    {
        return [
            'name' => 'تسهيل ' . fake()->company(),
            'limit_amount' => fake()->randomFloat(2, 100000, 5000000),
            'currency' => 'EGP',
            'status' => FacilityStatus::Active,
        ];
    }
}
