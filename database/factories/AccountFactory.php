<?php

namespace Database\Factories;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
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

    /** Place the account on a specific financial-statement line (ماليات.pptx). */
    public function inSection(StatementSection $section): static
    {
        return $this->state(fn () => ['statement_section' => $section]);
    }

    /** A contra account: natural side flipped against its type (مردودات / مجمع إهلاك). */
    public function contra(): static
    {
        return $this->state(fn (array $attributes) => [
            'nature' => ($attributes['type'] ?? AccountType::Asset)->naturalDirection() === AccountDirection::Debit
                ? AccountDirection::Credit
                : AccountDirection::Debit,
        ]);
    }
}
