<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\Project;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'item_id' => Item::factory(),
            'warehouse_type' => WarehouseType::RawMaterials,
            'quantity' => fake()->randomFloat(2, 1, 100),
            'status' => ReservationStatus::Active,
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Released,
            'released_at' => now(),
        ]);
    }
}
