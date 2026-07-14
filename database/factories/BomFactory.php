<?php

namespace Database\Factories;

use App\Enums\BomStatus;
use App\Enums\ItemType;
use App\Models\Bom;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BomFactory extends Factory
{
    protected $model = Bom::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'output_item_id' => null,
            'version' => fake()->numberBetween(1, 5),
            'status' => fake()->randomElement(BomStatus::cases()),
            'notes' => fake()->sentence(),
            'prepared_by' => User::factory(),
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ];
    }

    /**
     * A standard (product-scoped) BOM: the fixed recipe for a finished good,
     * with no project link. Approved by default so it is fetchable.
     */
    public function standard(?Item $outputItem = null): static
    {
        return $this->state(fn () => [
            'project_id' => null,
            'output_item_id' => $outputItem?->id
                ?? Item::factory()->create(['type' => ItemType::FinishedGood]),
            'status' => BomStatus::Approved,
        ]);
    }
}
