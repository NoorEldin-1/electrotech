<?php

namespace Database\Factories;

use App\Models\QualitySheet;
use App\Models\QualitySheetLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class QualitySheetLineFactory extends Factory
{
    protected $model = QualitySheetLine::class;

    public function definition(): array
    {
        return [
            'quality_sheet_id' => QualitySheet::factory(),
            'line_no' => fake()->numberBetween(1, 10),
        ];
    }
}
