<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\MasterData;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Final_Modifications Task 5 / R3 — the preliminary drawing, the executive
 * drawing and the site measurements are visible only to holders of
 * `attachments.view_restricted_drawings`. Everyone else must get them left out
 * of lists and a 403 on any direct access.
 */
class RestrictedDrawingsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // ------------------------------------------------------------------ enum

    public function test_exactly_the_three_drawing_categories_are_restricted(): void
    {
        $restricted = array_map(fn (AttachmentCategory $c): string => $c->value, AttachmentCategory::restricted());

        $this->assertEqualsCanonicalizing(['upload', 'executive_drawing', 'site_measurement'], $restricted);
        $this->assertTrue(AttachmentCategory::PreliminaryDrawing->isRestricted());
        $this->assertTrue(AttachmentCategory::ExecutiveDrawing->isRestricted());
        $this->assertTrue(AttachmentCategory::SiteMeasurement->isRestricted());
        $this->assertFalse(AttachmentCategory::Boq->isRestricted());
        $this->assertFalse(AttachmentCategory::PreliminaryApproval->isRestricted());
        $this->assertFalse(AttachmentCategory::Drowing->isRestricted());
    }

    public function test_only_the_timeline_coordinator_and_admin_hold_the_permission_out_of_the_box(): void
    {
        $this->assertTrue(Role::findByName('Timeline_Coordinator')->hasPermissionTo(AttachmentCategory::RESTRICTED_PERMISSION));
        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo(AttachmentCategory::RESTRICTED_PERMISSION));

        foreach (['General_Manager', 'Timeline_Viewer', 'Active_Operations_Viewer'] as $name) {
            $role = Role::where('name', $name)->first();
            if ($role !== null) {
                $this->assertFalse($role->hasPermissionTo(AttachmentCategory::RESTRICTED_PERMISSION), $name);
            }
        }
    }

    // ------------------------------------------------------------------- API

    public function test_the_list_leaves_restricted_drawings_out_without_the_permission(): void
    {
        [$project, $boq, $drawing, $executive, $measurement] = $this->projectWithFiles();
        $user = $this->userWith(['projects.view']);

        $response = $this->actingAsApi($user)->apiGet(
            self::BASE."/attachments?owner_type=project&owner_id={$project->id}",
        );

        $response->assertOk();
        $response->assertJsonPath('meta.count', 1);
        $this->assertSame([$boq->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($drawing->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($executive->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($measurement->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_the_list_includes_restricted_drawings_with_the_permission(): void
    {
        [$project] = $this->projectWithFiles();
        $user = $this->userWith(['projects.view', AttachmentCategory::RESTRICTED_PERMISSION]);

        $response = $this->actingAsApi($user)->apiGet(
            self::BASE."/attachments?owner_type=project&owner_id={$project->id}",
        );

        $response->assertOk();
        $response->assertJsonPath('meta.count', 4);
    }

    public function test_admin_sees_restricted_drawings(): void
    {
        [$project] = $this->projectWithFiles();

        $this->actingAsApi($this->admin())
            ->apiGet(self::BASE."/attachments?owner_type=project&owner_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('meta.count', 4);
    }

    public function test_filtering_by_a_restricted_category_is_forbidden_without_the_permission(): void
    {
        [$project] = $this->projectWithFiles();

        $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE."/attachments?owner_type=project&owner_id={$project->id}&category=executive_drawing")
            ->assertForbidden();

        $this->actingAsApi($this->userWith(['projects.view', AttachmentCategory::RESTRICTED_PERMISSION]))
            ->apiGet(self::BASE."/attachments?owner_type=project&owner_id={$project->id}&category=executive_drawing")
            ->assertOk()
            ->assertJsonPath('meta.count', 1);
    }

    public function test_downloading_a_restricted_drawing_is_forbidden_without_the_permission(): void
    {
        [, , $drawing] = $this->projectWithFiles();

        $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/attachments/'.$drawing->id.'/download')
            ->assertForbidden();
    }

    public function test_downloading_a_restricted_drawing_works_with_the_permission(): void
    {
        [, , $drawing] = $this->projectWithFiles();

        $response = $this->actingAsApi($this->userWith(['projects.view', AttachmentCategory::RESTRICTED_PERMISSION]))
            ->apiGet(self::BASE.'/attachments/'.$drawing->id.'/download');

        $response->assertOk();
        $this->assertSame('bytes-'.$drawing->id, $response->streamedContent());
    }

    public function test_an_unrestricted_file_still_downloads_without_the_permission(): void
    {
        [, $boq] = $this->projectWithFiles();

        $response = $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/attachments/'.$boq->id.'/download');

        $response->assertOk();
        $this->assertSame('bytes-'.$boq->id, $response->streamedContent());
    }

    public function test_uploading_a_restricted_drawing_is_forbidden_without_the_permission(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['projects.view', 'projects.edit']))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'project',
                'owner_id' => $project->id,
                'category' => 'site_measurement',
                'file' => UploadedFile::fake()->create('measure.pdf', 20, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertSame(0, Attachment::count());
    }

    public function test_uploading_a_restricted_drawing_works_with_the_permission(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['projects.view', 'projects.edit', AttachmentCategory::RESTRICTED_PERMISSION]))
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'project',
                'owner_id' => $project->id,
                'category' => 'upload',
                'file' => UploadedFile::fake()->create('prelim.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated();

        $this->assertSame(1, Attachment::count());
    }

    public function test_deleting_a_restricted_drawing_is_forbidden_without_the_permission(): void
    {
        [, , $drawing] = $this->projectWithFiles();

        $this->actingAsApi($this->userWith(['projects.view', 'projects.edit']))
            ->apiDelete(self::BASE.'/attachments/'.$drawing->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted('attachments', ['id' => $drawing->id]);
        Storage::disk('public')->assertExists($drawing->file_path);
    }

    public function test_the_restriction_follows_the_category_on_any_owner_type(): void
    {
        $supplier = \App\Models\Supplier::factory()->create();
        $user = $this->userWith(['suppliers.view', 'suppliers.edit']);

        $supplier->attachments()->create([
            'file_name' => 'measure.pdf',
            'file_path' => 'supplier-attachments/m.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1,
            'category' => AttachmentCategory::SiteMeasurement->value,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAsApi($user)
            ->apiGet(self::BASE."/attachments?owner_type=supplier&owner_id={$supplier->id}")
            ->assertOk()
            ->assertJsonPath('meta.count', 0);

        $this->actingAsApi($user)
            ->apiUpload(self::BASE.'/attachments', [
                'owner_type' => 'supplier',
                'owner_id' => $supplier->id,
                'category' => 'executive_drawing',
                'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    // ----------------------------------------------------------- model scope

    public function test_the_visible_to_scope_and_helper_follow_the_permission(): void
    {
        [, $boq, $drawing] = $this->projectWithFiles();

        $plain = $this->userWith(['projects.view']);
        $allowed = $this->userWith([AttachmentCategory::RESTRICTED_PERMISSION]);

        $this->assertSame([$boq->id], Attachment::query()->visibleTo($plain)->pluck('id')->all());
        $this->assertCount(4, Attachment::query()->visibleTo($allowed)->get());

        $this->assertFalse($drawing->isVisibleTo($plain));
        $this->assertTrue($drawing->isVisibleTo($allowed));
        $this->assertTrue($boq->isVisibleTo($plain));
    }

    // ------------------------------------------------------- activity log

    public function test_the_activity_log_hides_restricted_attachment_entries_without_the_permission(): void
    {
        [, $boq, $drawing] = $this->projectWithFiles();

        Activity::query()->delete();
        activity()->performedOn($boq)->log('boq filed');
        activity()->performedOn($drawing)->log('drawing filed');

        $this->actingAs($this->userWith(['activity_log.view']));
        $descriptions = ActivityResource::getEloquentQuery()->pluck('description')->all();
        $this->assertContains('boq filed', $descriptions);
        $this->assertNotContains('drawing filed', $descriptions);

        $this->actingAs($this->userWith(['activity_log.view', AttachmentCategory::RESTRICTED_PERMISSION]));
        $this->assertContains('drawing filed', ActivityResource::getEloquentQuery()->pluck('description')->all());
    }

    // ------------------------------------------------------------- Filament

    public function test_a_user_without_the_permission_cannot_file_or_wipe_restricted_drawings_from_the_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $existing = Attachment::factory()->create([
            'project_id' => $project->id,
            'category' => AttachmentCategory::PreliminaryDrawing->value,
            'file_path' => 'attachments/'.$project->id.'/upload/old.pdf',
        ]);
        Storage::disk('public')->put($existing->file_path, 'x');

        $this->actingAs($this->userWith(['projects.view', 'attachments.upload', 'attachments.download']));

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->mountAction('addAttachments')
            ->setActionData([
                'attachments_'.AttachmentCategory::Boq->value => [UploadedFile::fake()->create('boq.pdf', 10)],
                'attachments_'.AttachmentCategory::ExecutiveDrawing->value => [UploadedFile::fake()->create('exec.pdf', 10)],
                'attachments_'.AttachmentCategory::PreliminaryDrawing->value => [],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertModelExists($existing);
        Storage::disk('public')->assertExists($existing->file_path);
        $this->assertSame(1, $project->attachments()->where('category', AttachmentCategory::Boq->value)->count());
        $this->assertSame(0, $project->attachments()->where('category', AttachmentCategory::ExecutiveDrawing->value)->count());
    }

    public function test_a_user_with_the_permission_files_restricted_drawings_from_the_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $this->actingAs($this->userWith(['projects.view', 'attachments.upload', 'attachments.download', AttachmentCategory::RESTRICTED_PERMISSION]));

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->mountAction('addAttachments')
            ->setActionData([
                'attachments_'.AttachmentCategory::ExecutiveDrawing->value => [UploadedFile::fake()->create('exec.pdf', 10)],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, $project->attachments()->where('category', AttachmentCategory::ExecutiveDrawing->value)->count());
    }

    // --------------------------------------------------------------- helpers

    /**
     * A project with one BOQ and the three restricted drawings, all backed by
     * a real file on the fake disk.
     *
     * @return array{0: Project, 1: Attachment, 2: Attachment, 3: Attachment, 4: Attachment}
     */
    private function projectWithFiles(): array
    {
        $project = Project::factory()->create();
        $uploader = User::factory()->create();

        $make = function (AttachmentCategory $category) use ($project, $uploader): Attachment {
            $attachment = Attachment::create([
                'project_id' => $project->id,
                'file_name' => $category->value.'.pdf',
                'file_path' => "attachments/{$project->id}/{$category->value}/file.pdf",
                'file_type' => 'application/pdf',
                'file_size' => 10,
                'category' => $category->value,
                'uploaded_by' => $uploader->id,
            ]);
            Storage::disk('public')->put($attachment->file_path, 'bytes-'.$attachment->id);

            return $attachment;
        };

        return [
            $project,
            $make(AttachmentCategory::Boq),
            $make(AttachmentCategory::PreliminaryDrawing),
            $make(AttachmentCategory::ExecutiveDrawing),
            $make(AttachmentCategory::SiteMeasurement),
        ];
    }
}
