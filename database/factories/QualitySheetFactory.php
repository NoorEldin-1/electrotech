<?php

namespace Database\Factories;

use App\Enums\QualitySheetStatus;
use App\Models\QualitySheet;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class QualitySheetFactory extends Factory
{
    protected $model = QualitySheet::class;

    public function definition(): array
    {
        return [
            'sheet_number' => 'QS-' . fake()->unique()->numerify('######'),
            'work_order_id' => WorkOrder::factory(),
            'test_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'operation_name' => fake()->words(3, true),
            'status' => QualitySheetStatus::Draft,
        ];
    }

    public function qaFilled(): static
    {
        return $this->state(fn () => [
            'status' => QualitySheetStatus::QaFilled,
            'qa_filled_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => QualitySheetStatus::Approved,
            'qa_filled_at' => now(),
            'factory_approved_at' => now(),
        ]);
    }
}
