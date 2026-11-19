<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectManagement;

use App\Enums\ProjectStatus;
use App\Filament\Pages\ProjectsTimeline;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Models\CustomerInteraction;
use App\Models\Project;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Fix wave B, security S3 — the customer-communication feed is the
 * customer-interactions log (notes and all). It reaches the operation
 * timeline (web slide-over, REST API) and the operation file only for users
 * who hold `customer_interactions.view`; everyone else gets an empty feed.
 */
class InteractionFeedAccessTest extends ApiTestCase
{
    private const SECRET = 'Customer asked for a 12 percent discount';

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        CustomerInteraction::factory()->create([
            'project_id' => $this->project->id,
            'summary' => 'Pricing call',
            'notes' => self::SECRET,
        ]);
    }

    // ----------------------------------------------------------------- API

    public function test_the_api_timeline_feed_is_empty_without_the_interactions_permission(): void
    {
        $response = $this->actingAsApi($this->userWith(['operations.timeline']))
            ->apiGet(self::BASE."/projects/{$this->project->id}/timeline");

        $response->assertOk();
        $response->assertJsonCount(18, 'data.stages');
        $response->assertJsonPath('data.interactions.total', 0);
        $response->assertJsonPath('data.interactions.items', []);
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    public function test_the_api_timeline_feed_is_filled_with_the_interactions_permission(): void
    {
        $response = $this->actingAsApi($this->userWith(['operations.timeline', 'customer_interactions.view']))
            ->apiGet(self::BASE."/projects/{$this->project->id}/timeline");

        $response->assertOk();
        $response->assertJsonPath('data.interactions.total', 1);
        $response->assertJsonPath('data.interactions.items.0.notes', self::SECRET);
    }

    public function test_the_overview_permission_alone_does_not_open_the_feed_either(): void
    {
        $response = $this->actingAsApi($this->userWith(['operations.overview']))
            ->apiGet(self::BASE."/projects/{$this->project->id}/timeline");

        $response->assertOk();
        $response->assertJsonPath('data.interactions.total', 0);
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    public function test_the_admin_and_the_general_manager_still_get_the_feed(): void
    {
        $manager = $this->userWithoutPermissions();
        $manager->assignRole('General_Manager');

        foreach ([$this->admin(), $manager->fresh()] as $user) {
            $this->actingAsApi($user)
                ->apiGet(self::BASE."/projects/{$this->project->id}/timeline")
                ->assertOk()
                ->assertJsonPath('data.interactions.total', 1);
        }
    }

    // ------------------------------------------------------- web timeline

    public function test_the_timeline_page_payload_is_empty_without_the_interactions_permission(): void
    {
        $this->actingAs($this->userWith(['operations.timeline', 'projects.view']));

        $payload = collect(Livewire::test(ProjectsTimeline::class)->instance()->timelinePayload())->keyBy('id');

        $this->assertSame(['total' => 0, 'items' => []], $payload[$this->project->id]['interactions']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($payload->all(), JSON_UNESCAPED_UNICODE));
        // The eighteen stages are unaffected.
        $stages = collect($payload[$this->project->id]['groups'])->flatMap(fn (array $g): array => $g['stages']);
        $this->assertCount(18, $stages);
    }

    public function test_the_timeline_page_payload_carries_the_feed_with_the_interactions_permission(): void
    {
        $this->actingAs($this->userWith(['operations.timeline', 'projects.view', 'customer_interactions.view']));

        $payload = collect(Livewire::test(ProjectsTimeline::class)->instance()->timelinePayload())->keyBy('id');

        $this->assertSame(1, $payload[$this->project->id]['interactions']['total']);
        $this->assertSame(self::SECRET, $payload[$this->project->id]['interactions']['items'][0]['notes']);
    }

    public function test_the_timeline_page_does_not_even_query_the_feed_without_the_permission(): void
    {
        $this->actingAs($this->userWith(['operations.timeline', 'projects.view']));
        $page = Livewire::test(ProjectsTimeline::class)->instance();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->timelinePayload();
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $q): string => strtolower($q));
        DB::disableQueryLog();

        // The ranked feed query is the only one that uses a window function.
        $this->assertFalse($queries->contains(fn (string $q): bool => str_contains($q, 'row_number')));
    }

    // ------------------------------------------------------ operation file

    public function test_the_operation_file_shows_the_communication_block_only_with_the_interactions_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($this->userWith(['projects.view', 'projects.edit', 'attachments.download']));
        Livewire::test(EditProject::class, ['record' => $this->project->getRouteKey()])
            ->assertDontSee(self::SECRET)
            ->assertDontSee('Pricing call');

        $this->actingAs($this->userWith(['projects.view', 'projects.edit', 'attachments.download', 'customer_interactions.view']));
        Livewire::test(EditProject::class, ['record' => $this->project->getRouteKey()])
            ->assertSee(self::SECRET)
            ->assertSee('Pricing call');
    }
}
