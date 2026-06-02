<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $type = fake()->randomElement(AccountType::cases());

        return [
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->unique()->words(2, true),
            'type' => $type,
            'nature' => $type->naturalDirection(),
            'currency' => 'EGP',
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }

    public function ofType(AccountType $type): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'nature' => $type->naturalDirection(),
        ]);
    }

    public function withOpeningBalance(float $amount): static
    {
        return $this->state(fn () => [
            'opening_balance' => $amount,
            'opening_balance_date' => now()->startOfYear()->toDateString(),
        ]);
    }
}
