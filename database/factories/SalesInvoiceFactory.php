<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeliveryVoucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SalesInvoice>
 */
class SalesInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-' . fake()->unique()->numberBetween(1000, 999999),
            'delivery_voucher_id' => DeliveryVoucher::factory(),
            'invoice_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'notes' => null,
        ];
    }
}
