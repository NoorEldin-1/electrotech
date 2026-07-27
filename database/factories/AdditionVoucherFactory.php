<?php

namespace Database\Factories;

use App\Enums\VoucherStatus;
use App\Models\AdditionVoucher;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdditionVoucherFactory extends Factory
{
    protected $model = AdditionVoucher::class;

    public function definition(): array
    {
        return [
            'voucher_number' => 'AV-' . fake()->unique()->numerify('######'),
            'supplier_id' => Supplier::factory(),
            'invoice_number' => fake()->numerify('INV-#####'),
            'invoice_value' => fake()->randomFloat(2, 100, 10000),
            'voucher_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'status' => VoucherStatus::Draft,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => ['status' => VoucherStatus::Posted, 'posted_at' => now()]);
    }

    /**
     * A receipt whose supplier invoice has not arrived yet (سلايد 11) — the
     * state finance chases.
     */
    public function uninvoiced(): static
    {
        return $this->state(fn () => [
            'invoice_number' => null,
            'invoice_date' => null,
            'invoice_value' => 0,
        ]);
    }
}
