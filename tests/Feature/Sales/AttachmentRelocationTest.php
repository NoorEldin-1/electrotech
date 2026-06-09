<?php

namespace Tests\Feature\Sales;

use App\Filament\Resources\ProjectResource\Pages\AttachmentPersistence;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Create-screen uploads land under attachments/new/… because the project has
 * no id yet. AttachmentPersistence must relocate them into the project's own
 * folder so two projects can't overwrite each other's same-named files.
 */
class AttachmentRelocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_upload_is_moved_into_the_project_folder(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $project = Project::factory()->create();
        Storage::disk('public')->put('attachments/new/boq/sheet.pdf', 'PDF-BYTES');

        AttachmentPersistence::sync($project, ['boq' => ['attachments/new/boq/sheet.pdf']]);

        $expected = "attachments/{$project->id}/boq/sheet.pdf";
        Storage::disk('public')->assertExists($expected);
        Storage::disk('public')->assertMissing('attachments/new/boq/sheet.pdf');

        $this->assertSame($expected, $project->attachments()->first()->file_path);
    }

    public function test_same_filename_in_two_projects_does_not_collide(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $a = Project::factory()->create();
        $b = Project::factory()->create();

        Storage::disk('public')->put('attachments/new/boq/list.pdf', 'A');
        AttachmentPersistence::sync($a, ['boq' => ['attachments/new/boq/list.pdf']]);

        Storage::disk('public')->put('attachments/new/boq/list.pdf', 'B');
        AttachmentPersistence::sync($b, ['boq' => ['attachments/new/boq/list.pdf']]);

        Storage::disk('public')->assertExists("attachments/{$a->id}/boq/list.pdf");
        Storage::disk('public')->assertExists("attachments/{$b->id}/boq/list.pdf");
        $this->assertSame('A', Storage::disk('public')->get("attachments/{$a->id}/boq/list.pdf"));
        $this->assertSame('B', Storage::disk('public')->get("attachments/{$b->id}/boq/list.pdf"));
    }

    public function test_already_scoped_path_is_left_untouched(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $project = Project::factory()->create();
        $path = "attachments/{$project->id}/drowing/plan.dwg";
        Storage::disk('public')->put($path, 'DWG');

        AttachmentPersistence::sync($project, ['drowing' => [$path]]);

        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $project->attachments()->first()->file_path);
    }
}
