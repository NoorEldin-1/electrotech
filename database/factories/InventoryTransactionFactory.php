<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'type' => fake()->randomElement(TransactionType::cases()),
            'quantity' => fake()->randomFloat(4, 1, 100),
            'notes' => fake()->sentence(),
            'performed_by' => User::factory(),
        ];
    }
}
