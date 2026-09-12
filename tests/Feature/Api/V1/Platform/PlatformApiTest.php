<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Platform;

use App\Enums\ProjectStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * The cross-cutting surface: the dashboard, notifications, the activity log
 * and global search.
 *
 * The claims that matter here are about SCOPE — notifications are self-scoped
 * with no permission at all, search respects each type's own view permission,
 * and the activity log is read-only.
 */
class PlatformApiTest extends ApiTestCase
{
    // ------------------------------------------------------------- dashboard

    public function test_the_dashboard_counts_what_the_panel_counts(): void
    {
        Project::factory()->count(2)->create(['status' => ProjectStatus::InProgress]);

        // Pinned to ONE draft operation: WorkOrderFactory creates a project
        // per order, and ProjectFactory randomizes its status — left to
        // itself this fixture would count a different number of "active
        // operations" on different runs.
        $parked = Project::factory()->create(['status' => ProjectStatus::Draft]);
        WorkOrder::factory()->count(3)->create([
            'project_id' => $parked->id,
            'status' => WorkOrderStatus::InProgress,
        ]);

        $response = $this->actingAsApi($this->userWith(['dashboard.view']))
            ->apiGet(self::BASE.'/dashboard');

        $response->assertOk();
        $this->assertItemEnvelope($response);
        $response->assertJsonPath('data.active_projects', 2);
        $response->assertJsonPath('data.active_work_orders', 3);
        $this->assertNotNull($response->json('data.generated_at'));
    }

    public function test_the_dashboard_reads_the_panels_own_cache_keys(): void
    {
        // Not a coincidence to be kept in step by hand: a phone and a desktop
        // show the same figures because they read the same keys.
        Cache::put('dashboard:active_projects', 41, now()->addMinutes(5));

        $this->actingAsApi($this->userWith(['dashboard.view']))
            ->apiGet(self::BASE.'/dashboard')
            ->assertOk()
            ->assertJsonPath('data.active_projects', 41);
    }

    public function test_the_dashboard_is_permission_gated(): void
    {
        $response = $this->actingAsApi($this->userWithoutPermissions())
            ->apiGet(self::BASE.'/dashboard');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    // --------------------------------------------------------- notifications

    public function test_notifications_are_self_scoped_and_need_no_permission(): void
    {
        // A user with NO permissions at all can still read their own bell:
        // the only question a notification raises is whether you are the
        // person it was addressed to.
        $user = $this->userWithoutPermissions();
        $this->notify($user, 'Manufacturing finished');

        $response = $this->actingAsApi($user)->apiGet(self::BASE.'/notifications');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.0.data.title', 'Manufacturing finished');
        $response->assertJsonPath('data.0.read', false);
    }

    public function test_one_user_never_sees_anothers_notifications(): void
    {
        $mine = $this->userWithoutPermissions();
        $theirs = $this->userWithoutPermissions();

        $this->notify($theirs, 'Not for you');

        $this->actingAsApi($mine)
            ->apiGet(self::BASE.'/notifications')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_marking_another_users_notification_is_a_404_not_a_403(): void
    {
        $mine = $this->userWithoutPermissions();
        $theirs = $this->userWithoutPermissions();

        $id = $this->notify($theirs, 'Not for you');

        $response = $this->actingAsApi($mine)
            ->apiPost(self::BASE.'/notifications/'.$id.'/read');

        // Answering "forbidden" would confirm the notification exists, which
        // is more than a caller who cannot read it should learn.
        $response->assertStatus(404);
        $this->assertErrorEnvelope($response, 'not_found');
    }

    public function test_the_unread_count_and_marking_all_read(): void
    {
        $user = $this->userWithoutPermissions();
        $this->notify($user, 'One');
        $this->notify($user, 'Two');

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 2);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/notifications/read-all')
            ->assertOk()
            // How many actually changed, so a client can say "2 cleared"
            // rather than guessing.
            ->assertJsonPath('data.marked', 2)
            ->assertJsonPath('data.unread', 0);

        $this->actingAsApi($user)
            ->apiGet(self::BASE.'/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 0);
    }

    public function test_unread_filtering_and_marking_one_read(): void
    {
        $user = $this->userWithoutPermissions();
        $unreadId = $this->notify($user, 'Unread');
        $readId = $this->notify($user, 'Read');

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/notifications/'.$readId.'/read')
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $response = $this->actingAsApi($user)
            ->apiGet(self::BASE.'/notifications?filter[unread]=true');

        $response->assertOk();
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.0.id', $unreadId);
    }

    public function test_a_notification_can_be_deleted_by_its_owner_only(): void
    {
        $mine = $this->userWithoutPermissions();
        $theirs = $this->userWithoutPermissions();

        $id = $this->notify($mine, 'Mine');
        $notMine = $this->notify($theirs, 'Theirs');

        $this->actingAsApi($mine)
            ->apiDelete(self::BASE.'/notifications/'.$notMine)
            ->assertStatus(404);

        $this->actingAsApi($mine)
            ->apiDelete(self::BASE.'/notifications/'.$id)
            ->assertNoContent();

        $this->assertSame(0, $mine->notifications()->count());
    }

    // --------------------------------------------------------- activity log

    public function test_the_activity_log_reads_and_filters_by_subject(): void
    {
        $order = WorkOrder::factory()->create();
        $order->update(['title' => 'Renamed for the log']);

        $response = $this->actingAsApi($this->userWith(['activity_log.view']))
            ->apiGet(self::BASE.'/activity-log?filter[subject_type]='.urlencode(WorkOrder::class).'&filter[subject_id]='.$order->id);

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);
        $this->assertGreaterThan(0, $response->json('meta.pagination.total'));
        $response->assertJsonPath('data.0.subject_id', $order->id);

        // The properties bag is evidence, passed through as recorded.
        $this->assertArrayHasKey('properties', $response->json('data.0'));
    }

    public function test_the_activity_log_cannot_be_written_through_the_api(): void
    {
        $user = $this->userWith(['activity_log.view']);

        // A log that could be edited is not a log. There are no write routes.
        $this->actingAsApi($user)->apiPost(self::BASE.'/activity-log', [])->assertStatus(405);
    }

    public function test_the_activity_log_is_permission_gated(): void
    {
        $response = $this->actingAsApi($this->userWithoutPermissions())
            ->apiGet(self::BASE.'/activity-log');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
    }

    // --------------------------------------------------------------- search

    public function test_search_returns_hits_across_the_types_the_caller_may_see(): void
    {
        Project::factory()->create(['code' => '2026-14', 'name' => 'Cairo tower board']);
        Item::factory()->create(['sku' => 'SKU-2026-14X', 'name' => 'Busbar']);

        $response = $this->actingAsApi($this->userWith(['projects.view', 'items.view']))
            ->apiGet(self::BASE.'/search?q=2026-14');

        $response->assertOk();
        $response->assertJsonPath('data.query', '2026-14');

        // What was ACTUALLY searched, after permissions — so a client can say
        // so rather than presenting a permission gap as an absence.
        $this->assertSame(['projects', 'items'], $response->json('data.types'));

        $response->assertJsonPath('data.results.projects.0.label', '2026-14');
        $response->assertJsonPath('data.results.items.0.label', 'SKU-2026-14X');
    }

    public function test_search_never_reaches_a_type_the_caller_cannot_view(): void
    {
        Supplier::factory()->create(['name' => 'Findable Supplier']);
        Customer::factory()->create(['name' => 'Findable Customer']);

        $response = $this->actingAsApi($this->userWith(['customers.view']))
            ->apiGet(self::BASE.'/search?q=Findable');

        $response->assertOk();

        // A global search that ignored per-type permissions would be the
        // easiest way in the platform to confirm a record exists without being
        // allowed to read it.
        $this->assertSame(['customers'], $response->json('data.types'));
        $this->assertArrayNotHasKey('suppliers', $response->json('data.results'));
        $this->assertCount(1, $response->json('data.results.customers'));
    }

    public function test_a_one_character_term_is_refused(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/search?q=2');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $response->assertJsonStructure(['error' => ['details' => ['q']]]);
    }

    public function test_an_unknown_search_type_is_refused_and_names_what_exists(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.view']))
            ->apiGet(self::BASE.'/search?q=panel&types=projects,nonsense');

        // The same rule ApiQuery applies to filters, for the same reason: a
        // client should not ship a search that never worked.
        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'validation_failed');
        $this->assertStringContainsString('nonsense', $response->json('error.details.types.0'));
    }

    public function test_search_is_capped_per_type(): void
    {
        Item::factory()->count(12)->create(['name' => 'Copper busbar']);

        $response = $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/search?q=Copper busbar&limit=5');

        $response->assertOk();

        // A jump-to, not a report: anything that needs paging belongs on the
        // type's own index.
        $this->assertCount(5, $response->json('data.results.items'));
    }

    public function test_a_wildcard_in_the_term_is_escaped(): void
    {
        Item::factory()->create(['sku' => 'SKU-100PCT', 'name' => 'Hundred percent']);
        Item::factory()->create(['sku' => 'SKU-1000', 'name' => 'Thousand']);

        $response = $this->actingAsApi($this->userWith(['items.view']))
            ->apiGet(self::BASE.'/search?q='.urlencode('100%'));

        $response->assertOk();

        // Without escaping, "100%" would match everything beginning with 100.
        $this->assertSame([], $response->json('data.results.items'));
    }

    // --------------------------------------------------------------- gates

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/dashboard')->assertStatus(401);
        $this->apiGet(self::BASE.'/notifications')->assertStatus(401);
        $this->apiGet(self::BASE.'/activity-log')->assertStatus(401);
        $this->apiGet(self::BASE.'/search?q=panel')->assertStatus(401);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Write one database notification for a user, in the shape the panel
     * produces, and return its id.
     */
    private function notify(User $user, string $title): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\\Notifications\\PlatformEvent',
            'data' => ['title' => $title, 'body' => 'Body of '.$title],
            'read_at' => null,
        ]);

        return $id;
    }
}
