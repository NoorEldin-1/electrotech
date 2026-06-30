<?php

namespace Database\Factories;

use App\Enums\LossType;
use App\Enums\VoucherStatus;
use App\Models\DepreciationVoucher;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepreciationVoucherFactory extends Factory
{
    protected $model = DepreciationVoucher::class;

    public function definition(): array
    {
        return [
            'voucher_number' => 'DPV-' . fake()->unique()->numerify('######'),
            'work_order_id' => WorkOrder::factory(),
            'voucher_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'loss_type' => LossType::Abnormal,
            'status' => VoucherStatus::Draft,
            'total_value' => 0,
        ];
    }

    public function natural(): static
    {
        return $this->state(fn () => ['loss_type' => LossType::Natural]);
    }

    public function posted(): static
    {
        return $this->state(fn () => ['status' => VoucherStatus::Posted, 'signed_at' => now()]);
    }
}
