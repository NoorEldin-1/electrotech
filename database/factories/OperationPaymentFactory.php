<?php

namespace Database\Factories;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Models\OperationPayment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class OperationPaymentFactory extends Factory
{
    protected $model = OperationPayment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'direction' => PaymentDirection::Incoming,
            'method' => PaymentMethod::Cash,
            'amount' => fake()->randomFloat(2, 500, 200000),
            'currency' => 'EGP',
            'payment_date' => now()->toDateString(),
        ];
    }

    public function outgoing(): static
    {
        return $this->state(fn () => ['direction' => PaymentDirection::Outgoing]);
    }
}
