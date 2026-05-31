<?php

namespace Database\Factories;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'file_name' => fake()->word() . '.pdf',
            'file_path' => 'attachments/' . fake()->uuid() . '.pdf',
            'file_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(1024, 10485760),
            'category' => fake()->randomElement(AttachmentCategory::cases())->value,
            'uploaded_by' => User::factory(),
        ];
    }
}
