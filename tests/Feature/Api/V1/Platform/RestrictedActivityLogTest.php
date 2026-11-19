<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Platform;

use App\Enums\AttachmentCategory;
use App\Filament\Resources\ActivityResource;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Support\RestrictedActivityFilter;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Final_Modifications Task 5 / R3, security fix S2 — an activity entry about a
 * restricted drawing carries the file's name and category, so the REST API's
 * activity log hides it exactly like the panel's does: from everyone without
 * `attachments.view_restricted_drawings`. Both read the same filter.
 */
class RestrictedActivityLogTest extends ApiTestCase
{
    private Project $project;

    private Attachment $boq;

    private Attachment $drawing;

    private Attachment $trashedMeasurement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->boq = $this->attachment(AttachmentCategory::Boq);
        $this->drawing = $this->attachment(AttachmentCategory::PreliminaryDrawing);
        $this->trashedMeasurement = $this->attachment(AttachmentCategory::SiteMeasurement);

        // Start from a clean log: only the entries below exist.
        Activity::query()->delete();
        activity()->performedOn($this->boq)->log('boq filed');
        activity()->performedOn($this->drawing)->log('drawing filed');
        activity()->performedOn($this->trashedMeasurement)->log('measurement filed');
        activity()->performedOn($this->project)->log('project touched');
        activity()->log('no subject at all');

        // The entry outlives the (soft-deleted) attachment it is about. The
        // deletion logs a line of its own; drop it so the log holds exactly
        // the five entries above.
        $before = (int) Activity::query()->max('id');
        $this->trashedMeasurement->delete();
        Activity::query()->where('id', '>', $before)->delete();
    }

    private function attachment(AttachmentCategory $category): Attachment
    {
        return Attachment::query()->create([
            'project_id' => $this->project->id,
            'file_name' => "{$category->value}.pdf",
            'file_path' => "attachments/{$this->project->id}/{$category->value}/file.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 10,
            'category' => $category->value,
            'uploaded_by' => User::factory()->create()->id,
        ]);
    }

    /** @return list<string> */
    private function descriptionsSeenBy(User $user): array
    {
        $response = $this->actingAsApi($user)->apiGet(self::BASE.'/activity-log?per_page=100');
        $response->assertOk();

        return collect($response->json('data'))->pluck('description')->all();
    }

    public function test_the_api_hides_entries_about_restricted_drawings_without_the_permission(): void
    {
        $seen = $this->descriptionsSeenBy($this->userWith(['activity_log.view']));

        $this->assertContains('boq filed', $seen);
        $this->assertContains('project touched', $seen);
        $this->assertContains('no subject at all', $seen);
        $this->assertNotContains('drawing filed', $seen);
        $this->assertNotContains('measurement filed', $seen, 'soft-deleted restricted attachments stay hidden');
    }

    public function test_the_api_shows_them_with_the_permission_and_to_the_admin(): void
    {
        foreach ([
            $this->userWith(['activity_log.view', AttachmentCategory::RESTRICTED_PERMISSION]),
            $this->admin(),
        ] as $user) {
            $seen = $this->descriptionsSeenBy($user);

            $this->assertContains('drawing filed', $seen);
            $this->assertContains('measurement filed', $seen);
            $this->assertContains('boq filed', $seen);
        }
    }

    public function test_filtering_by_the_restricted_subject_does_not_get_around_it(): void
    {
        $user = $this->userWith(['activity_log.view']);

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/activity-log?filter[subject_type]='.urlencode(Attachment::class).'&filter[subject_id]='.$this->drawing->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/activity-log?search=drawing')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_panel_and_the_api_apply_the_same_filter(): void
    {
        $plain = $this->userWith(['activity_log.view']);
        $allowed = $this->userWith(['activity_log.view', AttachmentCategory::RESTRICTED_PERMISSION]);

        $viaHelper = fn (User $user): array => RestrictedActivityFilter::apply(Activity::query(), $user)->pluck('description')->sort()->values()->all();

        $this->actingAs($plain);
        $viaPanel = ActivityResource::getEloquentQuery()->pluck('description')->sort()->values()->all();

        $this->assertSame($viaHelper($plain), $viaPanel);
        $this->assertSame($viaHelper($plain), collect($this->descriptionsSeenBy($plain))->sort()->values()->all());
        $this->assertCount(3, $viaHelper($plain));
        $this->assertCount(5, $viaHelper($allowed));
    }
}
