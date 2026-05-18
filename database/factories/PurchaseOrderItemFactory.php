<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'item_id' => Item::factory(),
            'quantity' => fake()->randomFloat(4, 1, 100),
            'unit_price' => fake()->randomFloat(2, 1, 500),
            'received_quantity' => 0,
        ];
    }
}
