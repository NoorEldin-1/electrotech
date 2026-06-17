<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Resources\TenderProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Services\SalesPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SMB and Submittal are one artifact (شريحة 11): the SMB tracked on the In-Hand
 * list is the project's Submittal file. Moving Tender → In-Hand uploads it as a
 * Submittal attachment, and the In-Hand SMB indicator reads that same file.
 */
class SmbSubmittalTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_smb_reflects_submittal_attachment_presence(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->inHand()->create();

        $this->assertFalse($project->hasSmb());

        $project->attachments()->create([
            'file_name' => 'smb.pdf',
            'file_path' => "attachments/{$project->id}/submittal/smb.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 1,
            'category' => AttachmentCategory::Submittal->value,
            'uploaded_by' => $user->id,
        ]);

        $this->assertTrue($project->fresh()->hasSmb());
    }

    public function test_store_submittal_file_creates_a_submittal_attachment(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $project = Project::factory()->tender()->create();
        $path = "attachments/{$project->id}/submittal/doc.pdf";
        Storage::disk('public')->put($path, 'PDF');

        $method = new ReflectionMethod(TenderProjectResource::class, 'storeSubmittalFile');
        $method->setAccessible(true);
        $method->invoke(null, $project, $path);

        $this->assertDatabaseHas('attachments', [
            'project_id' => $project->id,
            'category' => 'submittal',
            'file_name' => 'doc.pdf',
        ]);
        $this->assertTrue($project->fresh()->hasSmb());
    }

    public function test_move_to_in_hand_marks_smb_submitted_when_file_present(): void
    {
        $service = app(SalesPipelineService::class);

        $user = User::factory()->create();
        $project = Project::factory()->tender()->create();
        $project->attachments()->create([
            'file_name' => 'smb.pdf',
            'file_path' => "attachments/{$project->id}/submittal/smb.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 1,
            'category' => AttachmentCategory::Submittal->value,
            'uploaded_by' => $user->id,
        ]);

        $service->moveToInHand($project->fresh());

        $fresh = $project->fresh();
        $this->assertSame(ProjectStatus::InHand, $fresh->status);
        $this->assertSame('submitted', $fresh->smb_status);
    }

    public function test_move_to_in_hand_stays_pending_without_file(): void
    {
        $service = app(SalesPipelineService::class);

        $project = Project::factory()->tender()->create();

        $service->moveToInHand($project->fresh());

        $fresh = $project->fresh();
        $this->assertSame(ProjectStatus::InHand, $fresh->status);
        $this->assertSame('pending', $fresh->smb_status);
    }
}
