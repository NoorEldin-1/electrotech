<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'entry_number' => 'JV-' . fake()->unique()->numerify('######'),
            'document_number' => fake()->numerify('DOC-#####'),
            'document_type' => DocumentType::Settlement,
            'entry_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'description' => fake()->sentence(3),
            'status' => JournalStatus::Draft,
            'currency' => 'EGP',
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => JournalStatus::Posted,
            'posted_at' => now(),
        ]);
    }

    public function ofType(DocumentType $type): static
    {
        return $this->state(fn () => ['document_type' => $type]);
    }
}
