<?php

namespace Database\Factories;

use App\Enums\AccountDirection;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryLineFactory extends Factory
{
    protected $model = JournalEntryLine::class;

    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'account_id' => Account::factory(),
            'direction' => AccountDirection::Debit,
            'amount' => fake()->randomFloat(2, 100, 10000),
        ];
    }

    public function debit(float $amount): static
    {
        return $this->state(fn () => ['direction' => AccountDirection::Debit, 'amount' => $amount]);
    }

    public function credit(float $amount): static
    {
        return $this->state(fn () => ['direction' => AccountDirection::Credit, 'amount' => $amount]);
    }
}
