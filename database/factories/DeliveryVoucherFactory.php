<?php

namespace Database\Factories;

use App\Enums\DeliveryVoucherStatus;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryVoucherFactory extends Factory
{
    protected $model = DeliveryVoucher::class;

    public function definition(): array
    {
        return [
            'voucher_number' => 'DV-' . fake()->unique()->numerify('######'),
            'customer_id' => Customer::factory(),
            'supply_order_number' => fake()->numerify('SO-#####'),
            'voucher_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'plates_count' => fake()->numberBetween(1, 20),
            'protection_degree' => 'IP' . fake()->numberBetween(20, 68),
            'insulation_voltage' => fake()->numberBetween(1, 36) . 'kV',
            'status' => DeliveryVoucherStatus::Draft,
            'total_value' => 0,
        ];
    }
}
