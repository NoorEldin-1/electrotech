<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\MasterData;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\AttachmentPersistence;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Final_Modifications Task 5 / R3, security fix S1 — the restricted drawings
 * (preliminary / executive drawing, site measurements) are stored on the
 * private `restricted` disk, which has no public URL, and are served only
 * through permission-checked routes. Before this, they sat on the public disk
 * where `/storage/attachments/{project}/{category}/{file}` could be guessed.
 */
class RestrictedStorageTest extends ApiTestCase
{
    private const MIGRATION = 'migrations/2026_11_19_100900_move_restricted_attachments_to_private_disk.php';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('restricted');
    }

    // ----------------------------------------------------------- disk setup

    public function test_the_restricted_disk_is_private_and_has_no_public_url(): void
    {
        $config = config('filesystems.disks.restricted');

        $this->assertIsArray($config);
        $this->assertSame('local', $config['driver']);
        $this->assertSame(storage_path('app/restricted'), $config['root']);
        $this->assertSame('private', $config['visibility']);
        $this->assertArrayNotHasKey('url', $config, 'a URL would make the files addressable');
        $this->assertArrayNotHasKey('serve', $config);

        // Not linked into public/ either: `storage:link` publishes only app/public.
        $this->assertSame([public_path('storage') => storage_path('app/public')], config('filesystems.links'));
    }

    public function test_each_category_knows_its_disk(): void
    {
        foreach (AttachmentCategory::cases() as $category) {
            $this->assertSame(
                $category->isRestricted() ? 'restricted' : 'public',
                $category->storageDisk(),
                $category->value,
            );
        }

        $this->assertSame('restricted', AttachmentCategory::diskFor('upload'));
        $this->assertSame('restricted', AttachmentCategory::diskFor(AttachmentCategory::SiteMeasurement));
        $this->assertSame('public', AttachmentCategory::diskFor('boq'));
        $this->assertSame('public', AttachmentCategory::diskFor('something_unknown'));
        $this->assertSame('public', AttachmentCategory::diskFor(null));
    }

    // ------------------------------------------------------------- API

    public function test_an_uploaded_restricted_drawing_lands_on_the_private_disk_only(): void
    {
        $project = Project::factory()->create();
        $user = $this->userWith(['projects.view', 'projects.edit', AttachmentCategory::RESTRICTED_PERMISSION]);

        foreach (['upload', 'executive_drawing', 'site_measurement'] as $category) {
            $this->actingAsApi($user)
                ->apiUpload(self::BASE.'/attachments', [
                    'owner_type' => 'project',
                    'owner_id' => $project->id,
                    'category' => $category,
                    'file' => UploadedFile::fake()->create("{$category}.pdf", 20, 'application/pdf'),
                ])
                ->assertCreated();

            $attachment = Attachment::query()->where('category', $category)->sole();

            Storage::disk('restricted')->assertExists($attachment->file_path);
            Storage::disk('public')->assertMissing($attachment->file_path);
        }
    }

    public function test_an_ordinary_upload_still_goes_to_the_public_disk(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['projects.view', 'projects.edit']))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'project',
                'owner_id' => $project->id,
                'category' => 'boq',
                'file' => UploadedFile::fake()->create('boq.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated();

        $attachment = Attachment::query()->sole();
        Storage::disk('public')->assertExists($attachment->file_path);
        Storage::disk('restricted')->assertMissing($attachment->file_path);
    }

    public function test_the_api_download_reads_from_the_private_disk_and_hardens_the_response(): void
    {
        $attachment = $this->restrictedFile(AttachmentCategory::ExecutiveDrawing);

        $response = $this->actingAsApi($this->userWith(['projects.view', AttachmentCategory::RESTRICTED_PERMISSION]))
            ->apiGet(self::BASE.'/attachments/'.$attachment->id.'/download');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('restricted-bytes', $response->streamedContent());
    }

    public function test_the_api_delete_removes_the_file_from_the_private_disk(): void
    {
        $attachment = $this->restrictedFile(AttachmentCategory::SiteMeasurement);

        $this->actingAsApi($this->userWith(['projects.view', 'projects.edit', AttachmentCategory::RESTRICTED_PERMISSION]))
            ->apiDelete(self::BASE.'/attachments/'.$attachment->id)
            ->assertNoContent();

        Storage::disk('restricted')->assertMissing($attachment->file_path);
        $this->assertSoftDeleted($attachment);
    }

    // ------------------------------------------------------ web download

    public function test_the_panel_download_route_is_forbidden_without_the_permission(): void
    {
        $attachment = $this->restrictedFile(AttachmentCategory::PreliminaryDrawing);
        $sales = $this->userWith(['projects.view', 'attachments.download']);

        $this->actingAs($sales)
            ->get(route('attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_the_panel_download_route_streams_the_file_to_a_user_with_the_permission(): void
    {
        $attachment = $this->restrictedFile(AttachmentCategory::PreliminaryDrawing);
        $coordinator = $this->userWith(['projects.view', AttachmentCategory::RESTRICTED_PERMISSION]);

        $response = $this->actingAs($coordinator)->get(route('attachments.download', $attachment));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('restricted-bytes', $response->streamedContent());
    }

    public function test_the_admin_downloads_a_restricted_drawing_through_the_panel_route(): void
    {
        $attachment = $this->restrictedFile(AttachmentCategory::SiteMeasurement);

        $this->actingAs($this->admin())
            ->get(route('attachments.download', $attachment))
            ->assertOk();
    }

    public function test_the_panel_download_route_also_needs_the_owner_view_permission(): void
    {
        $attachment = $this->restrictedFile(AttachmentCategory::ExecutiveDrawing);

        // Holds the restricted permission but cannot view projects at all.
        $this->actingAs($this->userWith([AttachmentCategory::RESTRICTED_PERMISSION]))
            ->get(route('attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_the_panel_download_route_serves_ordinary_files_by_the_owner_policy(): void
    {
        $supplier = Supplier::factory()->create();
        $attachment = $supplier->attachments()->create([
            'file_name' => 'tax-card.pdf',
            'file_path' => "supplier-attachments/{$supplier->id}/tax_card/card.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 5,
            'category' => AttachmentCategory::TaxCard->value,
            'uploaded_by' => User::factory()->create()->id,
        ]);
        Storage::disk('public')->put($attachment->file_path, 'public-bytes');

        $this->actingAs($this->userWith(['suppliers.view']))
            ->get(route('attachments.download', $attachment))
            ->assertOk();

        $this->actingAs($this->userWithoutPermissions())
            ->get(route('attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_the_panel_download_route_answers_404_for_a_missing_file_a_deleted_row_and_an_orphan(): void
    {
        $user = $this->userWith(['projects.view', AttachmentCategory::RESTRICTED_PERMISSION]);

        $missing = $this->restrictedFile(AttachmentCategory::ExecutiveDrawing);
        Storage::disk('restricted')->delete($missing->file_path);
        $this->actingAs($user)->get(route('attachments.download', $missing))->assertNotFound();

        $deleted = $this->restrictedFile(AttachmentCategory::SiteMeasurement);
        $deleted->delete();
        $this->actingAs($user)->get(route('attachments.download', $deleted->id))->assertNotFound();

        $orphan = Attachment::query()->create([
            'file_name' => 'x.pdf',
            'file_path' => 'attachments/0/boq/x.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1,
            'category' => AttachmentCategory::Boq->value,
            'uploaded_by' => $user->id,
        ]);
        $this->actingAs($user)->get(route('attachments.download', $orphan))->assertNotFound();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $attachment = $this->restrictedFile(AttachmentCategory::PreliminaryDrawing);

        $this->get(route('attachments.download', $attachment))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    // -------------------------------------------------------- Filament form

    public function test_the_project_form_stores_restricted_drawings_on_the_private_disk(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());

        $fields = collect(ProjectResource::attachmentCategorySchema())
            ->flatMap(fn ($fieldset) => $fieldset->getChildComponents())
            ->keyBy(fn ($field) => $field->getName());

        foreach (AttachmentCategory::projectCategories() as $category) {
            $field = $fields->get("attachments_{$category->value}");
            $this->assertNotNull($field, $category->value);
            $this->assertSame($category->storageDisk(), $field->getDiskName(), $category->value);
            $this->assertSame($category->isRestricted() ? 'private' : 'public', $field->getVisibility(), $category->value);
        }
    }

    public function test_a_create_screen_upload_of_a_restricted_category_is_relocated_and_removed_on_the_private_disk(): void
    {
        $this->actingAs($this->userWith([AttachmentCategory::RESTRICTED_PERMISSION]));
        $project = Project::factory()->create();

        Storage::disk('restricted')->put('attachments/new/executive_drawing/plan.dwg', 'DWG');

        AttachmentPersistence::sync($project, ['executive_drawing' => ['attachments/new/executive_drawing/plan.dwg']]);

        $expected = "attachments/{$project->id}/executive_drawing/plan.dwg";
        Storage::disk('restricted')->assertExists($expected);
        Storage::disk('restricted')->assertMissing('attachments/new/executive_drawing/plan.dwg');
        Storage::disk('public')->assertMissing($expected);
        $this->assertSame($expected, $project->attachments()->sole()->file_path);

        // Taking the file off the form removes it from the private disk too.
        AttachmentPersistence::sync($project, ['executive_drawing' => []]);

        Storage::disk('restricted')->assertMissing($expected);
        $this->assertSame(0, $project->attachments()->count());
    }

    public function test_the_form_links_a_restricted_drawing_to_the_download_route_not_to_a_storage_url(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $drawing = $this->restrictedFile(AttachmentCategory::ExecutiveDrawing, $project);
        $boq = $this->publicFile(AttachmentCategory::Boq, $project);

        $this->actingAs($this->admin());

        $page = Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])->instance();

        $restricted = array_values($page->getFormUploadedFiles('data.attachments_executive_drawing') ?? []);
        $this->assertCount(1, $restricted);
        $this->assertSame(route('attachments.download', $drawing), $restricted[0]['url']);
        $this->assertSame($drawing->file_name, $restricted[0]['name']);
        $this->assertStringNotContainsString('/storage/', $restricted[0]['url']);

        // An ordinary category keeps its public URL.
        $ordinary = array_values($page->getFormUploadedFiles('data.attachments_boq') ?? []);
        $this->assertCount(1, $ordinary);
        $this->assertStringContainsString('/storage/', $ordinary[0]['url']);
        $this->assertNotSame(route('attachments.download', $boq), $ordinary[0]['url']);
    }

    // ----------------------------------------------------------- migration

    public function test_the_migration_moves_every_restricted_file_off_the_public_disk(): void
    {
        $project = Project::factory()->create();

        // The way production looks today: everything on the public disk.
        $legacy = [];
        foreach (AttachmentCategory::restricted() as $category) {
            $legacy[$category->value] = $this->legacyFile($project, $category, "legacy-{$category->value}");
        }
        $boq = $this->legacyFile($project, AttachmentCategory::Boq, 'boq');

        // A soft-deleted row: its file is still on disk.
        $trashed = $this->legacyFile($project, AttachmentCategory::ExecutiveDrawing, 'trashed');
        $trashed->delete();

        // Files with no row at all: an abandoned Create-screen upload, and a
        // leftover in a project folder.
        Storage::disk('public')->put('attachments/new/upload/abandoned.pdf', 'abandoned');
        Storage::disk('public')->put("attachments/{$project->id}/site_measurement/orphan.pdf", 'orphan');
        // ... and an unrelated file that must stay where it is.
        Storage::disk('public')->put('attachments/new/boq/pending.pdf', 'pending-boq');

        $paths = DB::table('attachments')->pluck('file_path', 'id')->all();

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        foreach ([...array_values($legacy), $trashed] as $attachment) {
            Storage::disk('public')->assertMissing($attachment->file_path);
            Storage::disk('restricted')->assertExists($attachment->file_path);
            $this->assertSame('bytes-'.$attachment->file_name, Storage::disk('restricted')->get($attachment->file_path));
        }

        Storage::disk('public')->assertMissing('attachments/new/upload/abandoned.pdf');
        Storage::disk('restricted')->assertExists('attachments/new/upload/abandoned.pdf');
        Storage::disk('public')->assertMissing("attachments/{$project->id}/site_measurement/orphan.pdf");
        Storage::disk('restricted')->assertExists("attachments/{$project->id}/site_measurement/orphan.pdf");

        // Ordinary files are not touched.
        Storage::disk('public')->assertExists($boq->file_path);
        Storage::disk('restricted')->assertMissing($boq->file_path);
        Storage::disk('public')->assertExists('attachments/new/boq/pending.pdf');

        // No row was rewritten: the relative path is the same on the other disk.
        $this->assertSame($paths, DB::table('attachments')->pluck('file_path', 'id')->all());
    }

    public function test_the_migration_is_idempotent_and_finishes_a_half_done_move(): void
    {
        $project = Project::factory()->create();
        $moved = $this->legacyFile($project, AttachmentCategory::PreliminaryDrawing, 'moved');
        $half = $this->legacyFile($project, AttachmentCategory::ExecutiveDrawing, 'half');

        // `half` was copied by an interrupted run: it is on both disks, the same size.
        Storage::disk('restricted')->put($half->file_path, Storage::disk('public')->get($half->file_path));

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up();
        $migration->up();

        foreach ([$moved, $half] as $attachment) {
            Storage::disk('public')->assertMissing($attachment->file_path);
            Storage::disk('restricted')->assertExists($attachment->file_path);
            $this->assertSame('bytes-'.$attachment->file_name, Storage::disk('restricted')->get($attachment->file_path));
        }
    }

    public function test_the_migration_keeps_the_public_copy_when_the_private_copy_differs(): void
    {
        $project = Project::factory()->create();
        $attachment = $this->legacyFile($project, AttachmentCategory::SiteMeasurement, 'conflict');

        // A different file already sits at the target: never overwritten, never
        // trusted — the public copy stays for a human to look at.
        Storage::disk('restricted')->put($attachment->file_path, 'a different and longer file');

        Log::spy();
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        Storage::disk('public')->assertExists($attachment->file_path);
        $this->assertSame('a different and longer file', Storage::disk('restricted')->get($attachment->file_path));
        // Logged for the row, and again by the sweep that meets the same file.
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_the_migration_logs_a_row_whose_file_is_on_neither_disk_and_carries_on(): void
    {
        $project = Project::factory()->create();
        $gone = $this->legacyFile($project, AttachmentCategory::PreliminaryDrawing, 'gone');
        Storage::disk('public')->delete($gone->file_path);
        $present = $this->legacyFile($project, AttachmentCategory::ExecutiveDrawing, 'present');

        Log::spy();
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        Log::shouldHaveReceived('warning')->once();
        Storage::disk('restricted')->assertExists($present->file_path);
        Storage::disk('public')->assertMissing($present->file_path);
        $this->assertModelExists($gone);
    }

    public function test_the_migration_is_a_no_op_on_an_empty_attachments_table(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('restricted')->allFiles());
    }

    // --------------------------------------------------------------- helpers

    /** A restricted drawing with its file on the private disk. */
    private function restrictedFile(AttachmentCategory $category, ?Project $project = null): Attachment
    {
        $project ??= Project::factory()->create();

        $attachment = Attachment::query()->create([
            'project_id' => $project->id,
            'file_name' => "{$category->value}.pdf",
            'file_path' => "attachments/{$project->id}/{$category->value}/file.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 15,
            'category' => $category->value,
            'uploaded_by' => User::factory()->create()->id,
        ]);
        Storage::disk('restricted')->put($attachment->file_path, 'restricted-bytes');

        return $attachment;
    }

    /** An ordinary file on the public disk. */
    private function publicFile(AttachmentCategory $category, Project $project): Attachment
    {
        $attachment = Attachment::query()->create([
            'project_id' => $project->id,
            'file_name' => "{$category->value}.pdf",
            'file_path' => "attachments/{$project->id}/{$category->value}/file.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 12,
            'category' => $category->value,
            'uploaded_by' => User::factory()->create()->id,
        ]);
        Storage::disk('public')->put($attachment->file_path, 'public-bytes');

        return $attachment;
    }

    /** A file as it was stored before the move: on the public disk, whatever its category. */
    private function legacyFile(Project $project, AttachmentCategory $category, string $name): Attachment
    {
        $attachment = Attachment::query()->create([
            'project_id' => $project->id,
            'file_name' => "{$name}.pdf",
            'file_path' => "attachments/{$project->id}/{$category->value}/{$name}.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 10,
            'category' => $category->value,
            'uploaded_by' => User::factory()->create()->id,
        ]);
        Storage::disk('public')->put($attachment->file_path, "bytes-{$attachment->file_name}");

        return $attachment;
    }
}
