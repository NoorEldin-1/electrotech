<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectManagement;

use App\Models\CustomerInteraction;
use App\Models\Project;
use App\Models\User;
use App\Services\OperationTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fix wave B, S4 — the customer-communication feed must not load every logged
 * interaction of every operation just to show the newest few: the database
 * ranks them per operation (ROW_NUMBER() OVER PARTITION BY) and hands back
 * only the page, plus a separate COUNT for the total.
 */
class InteractionFeedLimitTest extends TestCase
{
    use RefreshDatabase;

    private OperationTimelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OperationTimelineService::class);
    }

    /** @return list<CustomerInteraction> oldest first */
    private function log(Project $project, int $count, string $prefix): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = CustomerInteraction::factory()->create([
                'project_id' => $project->id,
                'interacted_at' => now()->subDays($count - $i + 1),
                'summary' => "{$prefix} {$i}",
            ]);
        }

        return $rows;
    }

    public function test_each_operation_gets_only_its_newest_entries_and_its_own_total(): void
    {
        $busy = Project::factory()->create();
        $few = Project::factory()->create();
        $quiet = Project::factory()->create();

        $this->log($busy, 30, 'busy');
        $this->log($few, 2, 'few');

        $feeds = $this->service->interactionsForMany(collect([$busy, $few, $quiet]), 3);

        $this->assertSame(30, $feeds[$busy->id]['total']);
        $this->assertSame(['busy 30', 'busy 29', 'busy 28'], $feeds[$busy->id]['items']->pluck('summary')->all());

        $this->assertSame(2, $feeds[$few->id]['total']);
        $this->assertSame(['few 2', 'few 1'], $feeds[$few->id]['items']->pluck('summary')->all());

        $this->assertSame(0, $feeds[$quiet->id]['total']);
        $this->assertCount(0, $feeds[$quiet->id]['items']);
    }

    public function test_the_default_page_is_twenty_and_the_total_still_counts_everything(): void
    {
        $project = Project::factory()->create();
        $this->log($project, 25, 'call');

        $feed = $this->service->interactionsFor($project);

        $this->assertSame(OperationTimelineService::INTERACTION_FEED_LIMIT, 20);
        $this->assertSame(25, $feed['total']);
        $this->assertCount(20, $feed['items']);
        $this->assertSame('call 25', $feed['items']->first()['summary']);
        $this->assertSame('call 6', $feed['items']->last()['summary']);
    }

    public function test_entries_with_the_same_instant_are_ordered_by_id_newest_first(): void
    {
        $project = Project::factory()->create();
        $at = now()->subDay();

        $first = CustomerInteraction::factory()->create(['project_id' => $project->id, 'interacted_at' => $at, 'summary' => 'first']);
        $second = CustomerInteraction::factory()->create(['project_id' => $project->id, 'interacted_at' => $at, 'summary' => 'second']);
        $third = CustomerInteraction::factory()->create(['project_id' => $project->id, 'interacted_at' => $at, 'summary' => 'third']);

        $feed = $this->service->interactionsFor($project, 2);

        $this->assertSame([$third->id, $second->id], $feed['items']->pluck('id')->all());
        $this->assertSame(3, $feed['total']);
        $this->assertNotContains($first->id, $feed['items']->pluck('id')->all());
    }

    public function test_deleted_entries_are_in_neither_the_page_nor_the_total(): void
    {
        $project = Project::factory()->create();
        $rows = $this->log($project, 5, 'call');
        $rows[4]->delete(); // the newest one

        $feed = $this->service->interactionsFor($project, 2);

        $this->assertSame(4, $feed['total']);
        $this->assertSame(['call 4', 'call 3'], $feed['items']->pluck('summary')->all());
    }

    public function test_the_page_is_ranked_in_sql_with_a_fixed_number_of_queries(): void
    {
        $author = User::factory()->create();
        $projects = Project::factory()->count(4)->create();
        foreach ($projects as $project) {
            CustomerInteraction::factory()->count(12)->create(['project_id' => $project->id, 'created_by' => $author->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $feeds = $this->service->interactionsForMany($projects, 5);
        $log = collect(DB::getQueryLog())->pluck('query')->map(fn (string $q): string => strtolower($q));
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $log->count(), 'totals + ranked page + authors, however many operations');
        $this->assertTrue($log->contains(fn (string $q): bool => str_contains($q, 'row_number() over (partition by project_id')), 'the limit is applied by the database');
        $this->assertTrue($log->contains(fn (string $q): bool => str_contains($q, 'count(*)')), 'the total is its own count query');

        foreach ($projects as $project) {
            $this->assertSame(12, $feeds[$project->id]['total']);
            $this->assertCount(5, $feeds[$project->id]['items']);
            $this->assertSame($author->name, $feeds[$project->id]['items']->first()['recorded_by']);
        }
    }

    public function test_a_zero_limit_returns_no_items_but_the_total(): void
    {
        $project = Project::factory()->create();
        $this->log($project, 3, 'call');

        $feed = $this->service->interactionsFor($project, 0);

        $this->assertSame(3, $feed['total']);
        $this->assertCount(0, $feed['items']);
    }

    public function test_no_projects_means_no_feeds(): void
    {
        $this->assertSame([], $this->service->interactionsForMany(collect()));
    }
}
