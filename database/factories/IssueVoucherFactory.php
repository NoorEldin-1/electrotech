<?php

namespace Database\Factories;

use App\Enums\VoucherStatus;
use App\Models\IssueVoucher;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class IssueVoucherFactory extends Factory
{
    protected $model = IssueVoucher::class;

    public function definition(): array
    {
        return [
            'voucher_number' => 'ISV-' . fake()->unique()->numerify('######'),
            'work_order_id' => WorkOrder::factory(),
            'voucher_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'status' => VoucherStatus::Draft,
            'total_value' => 0,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => ['status' => VoucherStatus::Posted, 'signed_at' => now()]);
    }
}
