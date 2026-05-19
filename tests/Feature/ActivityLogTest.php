<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Jobs\LogActivityJob;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Services\QueuedActivityLogger;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_app_binds_queued_activity_logger_in_place_of_default(): void
    {
        $this->assertInstanceOf(QueuedActivityLogger::class, app(ActivityLogger::class));
    }

    public function test_activity_rows_are_written_when_models_change_in_cli_context(): void
    {
        // Tests run via PHPUnit are a CLI context, so QueuedActivityLogger
        // persists inline (terminating() callbacks aren't reliable in CLI).
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->create();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'event' => 'created',
            'causer_id' => $user->id,
        ]);
    }

    public function test_explicit_log_activity_job_still_works_for_callers_wanting_queueing(): void
    {
        // The LogActivityJob class is kept as an opt-in queueable wrapper.
        // Dispatching it (sync queue in tests) should persist the row.
        $beforeCount = Activity::query()->count();

        LogActivityJob::dispatch([
            'log_name' => 'default',
            'description' => 'manual-job-dispatch',
            'event' => 'created',
            'properties' => '{}',
        ]);

        $this->assertSame($beforeCount + 1, Activity::query()->count());
        $this->assertDatabaseHas('activity_log', [
            'description' => 'manual-job-dispatch',
            'event' => 'created',
        ]);
    }

    public function test_updates_log_only_dirty_fields_with_old_and_new_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->create(['name' => 'Original']);
        $project->update(['name' => 'Renamed']);

        $activity = Activity::query()
            ->where('subject_type', Project::class)
            ->where('subject_id', $project->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'An update activity should be recorded.');
        $this->assertSame('Original', data_get($activity->properties->toArray(), 'old.name'));
        $this->assertSame('Renamed', data_get($activity->properties->toArray(), 'attributes.name'));
    }

    public function test_deletion_records_a_deleted_event(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $item = Item::factory()->create();
        $itemId = $item->id;
        $item->delete();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Item::class,
            'subject_id' => $itemId,
            'event' => 'deleted',
        ]);
    }

    public function test_user_without_permission_cannot_view_activity_log_resource(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales'); // Sales role does NOT have activity_log.view.
        $this->actingAs($user);

        $this->assertFalse(ActivityResource::canViewAny());
    }

    public function test_admin_can_view_activity_log_resource(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertTrue(ActivityResource::canViewAny());
    }

    public function test_activity_resource_cannot_be_created_edited_or_deleted_from_ui(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $activity = $this->makeActivityRecord();

        $this->assertFalse(ActivityResource::canCreate());
        $this->assertFalse(ActivityResource::canEdit($activity));
        $this->assertFalse(ActivityResource::canDelete($activity));
        $this->assertFalse(ActivityResource::canDeleteAny());
    }

    public function test_list_activities_page_renders_recent_entries(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // Generate some activity via real model events.
        Project::factory()->count(3)->create();

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Activity::query()->latest()->limit(3)->get());
    }

    public function test_view_activity_page_renders_for_authorised_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $activity = $this->makeActivityRecord();

        Livewire::test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_activity_log_supports_arabic_translation(): void
    {
        app()->setLocale('ar');

        $this->assertSame('سجل النشاط', __('resources.activities.navigation_label'));
        $this->assertSame('بواسطة', __('resources.activities.columns.causer'));
        $this->assertSame('إنشاء', __('resources.activities.events.created'));
    }

    public function test_activity_log_supports_english_translation(): void
    {
        app()->setLocale('en');

        $this->assertSame('Activity Log', __('resources.activities.navigation_label'));
        $this->assertSame('Performed By', __('resources.activities.columns.causer'));
        $this->assertSame('Created', __('resources.activities.events.created'));
    }

    public function test_activity_log_permission_exists_in_seeded_set(): void
    {
        $this->assertDatabaseHas('permissions', [
            'name' => 'activity_log.view',
            'guard_name' => 'web',
        ]);
    }

    public function test_activity_log_navigation_uses_system_group(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertSame(__('navigation.groups.system'), ActivityResource::getNavigationGroup());
    }

    public function test_description_is_translated_using_subject_type_and_event(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = \App\Models\Project::factory()->create();
        $activity = Activity::query()
            ->where('subject_type', \App\Models\Project::class)
            ->where('subject_id', $project->id)
            ->latest('id')
            ->firstOrFail();

        app()->setLocale('ar');
        $arabic = ActivityResource::formatDescription($activity);
        $this->assertStringContainsString('تم', $arabic);
        $this->assertStringContainsString('مشروع', $arabic);
        $this->assertStringContainsString('#' . $project->id, $arabic);

        app()->setLocale('en');
        $english = ActivityResource::formatDescription($activity);
        $this->assertStringContainsString('Project', $english);
        $this->assertStringContainsString('was', $english);
        $this->assertStringContainsString('#' . $project->id, $english);
    }

    public function test_record_title_uses_translated_description_for_view_page_heading(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = \App\Models\Project::factory()->create();
        $activity = Activity::query()
            ->where('subject_type', \App\Models\Project::class)
            ->where('subject_id', $project->id)
            ->latest('id')
            ->firstOrFail();

        app()->setLocale('ar');
        $title = ActivityResource::getRecordTitle($activity);

        $this->assertIsString($title);
        $this->assertStringNotContainsString('was created', $title);
        $this->assertStringContainsString('مشروع', $title);
    }

    public function test_subject_type_is_translated_to_locale_label(): void
    {
        app()->setLocale('ar');
        $this->assertSame('أمر تشغيل', ActivityResource::translateSubjectType(\App\Models\WorkOrder::class));
        $this->assertSame('مشروع', ActivityResource::translateSubjectType(\App\Models\Project::class));

        app()->setLocale('en');
        $this->assertSame('Work Order', ActivityResource::translateSubjectType(\App\Models\WorkOrder::class));
        $this->assertSame('Project', ActivityResource::translateSubjectType(\App\Models\Project::class));
    }

    public function test_unknown_subject_type_falls_back_to_class_basename(): void
    {
        $this->assertSame('SomeUnknownModel', ActivityResource::translateSubjectType('App\\Models\\SomeUnknownModel'));
    }

    public function test_default_log_name_is_translated(): void
    {
        app()->setLocale('ar');
        $this->assertSame('النظام', ActivityResource::formatLogName('default'));

        app()->setLocale('en');
        $this->assertSame('System', ActivityResource::formatLogName('default'));
    }

    public function test_event_formatter_translates_known_events_and_falls_back_for_unknown(): void
    {
        app()->setLocale('ar');
        $this->assertSame('إنشاء', ActivityResource::formatEvent('created'));
        $this->assertSame('تعديل', ActivityResource::formatEvent('updated'));

        // Unknown event → ucfirst fallback.
        $this->assertSame('Reopened', ActivityResource::formatEvent('reopened'));
    }

    public function test_format_description_falls_back_to_raw_description_when_no_subject(): void
    {
        $activity = new Activity([
            'log_name' => 'default',
            'description' => 'Manual log entry',
            'event' => null,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $this->assertSame('Manual log entry', ActivityResource::formatDescription($activity));
    }

    public function test_change_set_keys_are_translated_per_subject_type(): void
    {
        app()->setLocale('ar');

        $translated = ActivityResource::translateChangeSet(\App\Models\Item::class, [
            'name' => 'Widget',
            'sku' => 'W-1',
            'unit_cost' => '10.00',
            'minimum_stock' => '5',
        ]);

        // Keys come from `resources.items.fields.*` (per-model translations
        // take precedence over the generic activity-log bucket). The raw
        // English `name`, `sku`, ... must NOT appear as keys any more.
        $this->assertArrayNotHasKey('name', $translated);
        $this->assertArrayNotHasKey('sku', $translated);
        $this->assertArrayNotHasKey('unit_cost', $translated);
        $this->assertArrayNotHasKey('minimum_stock', $translated);

        // Spot-check that the keys are translated to Arabic.
        $this->assertArrayHasKey(__('resources.items.fields.name'), $translated);
        $this->assertArrayHasKey(__('resources.items.fields.sku'), $translated);
        $this->assertArrayHasKey(__('resources.items.fields.unit_cost'), $translated);
        $this->assertArrayHasKey(__('resources.items.fields.minimum_stock'), $translated);
    }

    public function test_change_set_enum_values_are_translated(): void
    {
        app()->setLocale('ar');

        $translated = ActivityResource::translateChangeSet(\App\Models\Item::class, [
            'type' => 'consumable',
            'unit' => 'pcs',
        ]);

        // Values should be translated via `resources.enums.*` groups.
        $this->assertContains('مستهلكات', $translated);
        $this->assertContains('قطعة', $translated);
    }

    public function test_change_set_status_enum_for_work_order_is_translated(): void
    {
        app()->setLocale('ar');

        $translated = ActivityResource::translateChangeSet(\App\Models\WorkOrder::class, [
            'status' => 'completed',
        ]);

        $this->assertContains('مكتمل', $translated);
    }

    public function test_field_label_falls_back_to_generic_bucket(): void
    {
        app()->setLocale('ar');

        // `qa_approved_by` is not in `resources.work_orders.fields` —
        // it must fall through to `resources.activities.field_labels`.
        $label = ActivityResource::translateFieldLabel('work_orders', 'qa_approved_by');

        $this->assertSame('اعتُمد الجودة بواسطة', $label);
    }

    public function test_field_label_humanizes_completely_unknown_keys(): void
    {
        // Unknown to all dictionaries → headline-cased fallback.
        $label = ActivityResource::translateFieldLabel('some_unknown_prefix', 'totally_made_up_field');

        $this->assertSame('Totally Made Up Field', $label);
    }

    public function test_view_page_no_longer_includes_full_properties_section(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // Create a Project so we have a real activity row with properties.
        $project = Project::factory()->create();
        $activity = Activity::query()
            ->where('subject_type', Project::class)
            ->where('subject_id', $project->id)
            ->latest('id')
            ->firstOrFail();

        $response = Livewire::test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertSuccessful();

        // The section header was "Raw Properties" / "الخصائص الكاملة";
        // confirm neither appears on the rendered page.
        $response->assertDontSee(__('resources.activities.sections.properties', [], 'en'));
        $response->assertDontSee('الخصائص الكاملة');
    }

    public function test_change_set_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], ActivityResource::translateChangeSet(\App\Models\Project::class, []));
        $this->assertSame([], ActivityResource::translateChangeSet(null, []));
    }

    public function test_change_set_handles_null_values_gracefully(): void
    {
        $translated = ActivityResource::translateChangeSet(\App\Models\Project::class, [
            'name' => null,
        ]);

        // Null values become the "no data" placeholder, not literal null.
        $this->assertNotEmpty($translated);
        $this->assertContains(__('resources.common.no_data'), $translated);
    }

    public function test_persist_activity_swallows_db_failures_so_callers_are_not_broken(): void
    {
        // Pass garbage that will fail at the DB layer. The helper must
        // catch the exception so the calling operation never breaks
        // because of audit logging.
        QueuedActivityLogger::persistActivity([
            'this_column_does_not_exist' => 'boom',
        ]);

        // No exception bubbled, no row written — that's the contract.
        $this->assertTrue(true);
    }

    protected function makeActivityRecord(): Activity
    {
        $project = Project::factory()->create();

        return Activity::query()
            ->where('subject_type', Project::class)
            ->where('subject_id', $project->id)
            ->latest('id')
            ->firstOrFail();
    }
}
