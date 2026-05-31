<?php

namespace Tests\Feature\Sales;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachmentCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_category_can_be_persisted(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        foreach (AttachmentCategory::cases() as $category) {
            $attachment = $project->attachments()->create([
                'file_name' => $category->value . '.pdf',
                'file_path' => 'attachments/' . $project->id . '/' . $category->value . '/x.pdf',
                'file_type' => 'application/pdf',
                'file_size' => 1024,
                'category' => $category->value,
                'uploaded_by' => $user->id,
            ]);

            $this->assertInstanceOf(AttachmentCategory::class, $attachment->fresh()->category);
            $this->assertSame($category, $attachment->fresh()->category);
        }
    }

    public function test_attachments_by_category_relation_filters_correctly(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        Attachment::factory()->for($project)->create([
            'category' => AttachmentCategory::Boq->value,
            'uploaded_by' => $user->id,
        ]);
        Attachment::factory()->for($project)->create([
            'category' => AttachmentCategory::Drowing->value,
            'uploaded_by' => $user->id,
        ]);

        $this->assertCount(1, $project->attachmentsByCategory(AttachmentCategory::Boq)->get());
        $this->assertCount(1, $project->attachmentsByCategory(AttachmentCategory::Drowing)->get());
        $this->assertCount(0, $project->attachmentsByCategory(AttachmentCategory::Speces)->get());
    }
}
