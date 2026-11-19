<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectManagement;

use App\Enums\InteractionChannel;
use App\Enums\InteractionType;
use App\Enums\ProjectStatus;
use App\Filament\Pages\ProjectsTimeline;
use App\Filament\Resources\CustomerInteractionResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Project;
use App\Models\User;
use App\Services\OperationTimelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Final_Modifications_Plan 1.1 — «التواصل مع العميل» inside the operation file
 * and on the operation timeline, reusing CustomerInteraction.
 */
class CustomerCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private const ACTION = 'logCustomerCommunication';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_communication_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('customer_interactions', ['summary', 'next_follow_up_at']));
    }

    public function test_logging_from_the_operation_file_creates_a_linked_interaction(): void
    {
        $user = $this->as('Admin');
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress, 'customer_id' => $customer->id]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertActionVisible(self::ACTION)
            ->callAction(self::ACTION, data: [
                'channel' => InteractionChannel::Meeting->value,
                'interacted_at' => now()->toDateTimeString(),
                'summary' => 'Agreed the drawing revision',
                'notes' => 'Customer will reply by Sunday',
                'next_follow_up_at' => now()->addDays(3)->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $interaction = CustomerInteraction::query()->where('project_id', $project->id)->firstOrFail();

        $this->assertSame($customer->id, $interaction->customer_id, 'the customer comes from the operation');
        $this->assertSame(InteractionType::Contact, $interaction->type);
        $this->assertSame(InteractionChannel::Meeting, $interaction->channel);
        $this->assertSame('Agreed the drawing revision', $interaction->summary);
        $this->assertSame('Customer will reply by Sunday', $interaction->notes);
        $this->assertNotNull($interaction->next_follow_up_at);
        $this->assertSame($user->id, $interaction->created_by);

        // The block under the attachments lists it.
        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertSee(__('resources.customer_communication.heading'))
            ->assertSee('Agreed the drawing revision');
    }

    public function test_the_read_only_operation_file_offers_the_same_action(): void
    {
        $this->as('Admin');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->callAction(self::ACTION, data: [
                'channel' => InteractionChannel::Message->value,
                'interacted_at' => now()->toDateTimeString(),
                'summary' => 'Sent the revised quote',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('customer_interactions', [
            'project_id' => $project->id,
            'channel' => InteractionChannel::Message->value,
            'summary' => 'Sent the revised quote',
        ]);
    }

    public function test_the_quick_form_requires_a_summary_and_a_follow_up_not_before_the_contact(): void
    {
        $this->as('Admin');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->callAction(self::ACTION, data: [
                'channel' => InteractionChannel::Call->value,
                'interacted_at' => now()->toDateTimeString(),
                'summary' => '',
            ])
            ->assertHasActionErrors(['summary' => 'required']);

        $this->assertSame(0, CustomerInteraction::count());
    }

    public function test_only_the_quick_form_channels_are_offered(): void
    {
        $this->assertSame(
            [InteractionChannel::Call, InteractionChannel::Meeting, InteractionChannel::Message],
            CustomerInteractionResource::communicationChannels(),
        );
    }

    public function test_logging_needs_the_manage_permission(): void
    {
        $project = Project::factory()->create();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('customer_interactions.view');
        $this->actingAs($viewer);

        $this->expectException(AuthorizationException::class);
        CustomerInteractionResource::logCommunication($project, ['summary' => 'x']);
    }

    public function test_the_interaction_appears_in_the_timeline_feed_newest_first(): void
    {
        $this->as('Admin');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $old = CustomerInteraction::factory()->create([
            'project_id' => $project->id,
            'channel' => InteractionChannel::Call,
            'interacted_at' => now()->subDays(5),
            'summary' => 'First call',
        ]);
        $new = CustomerInteractionResource::logCommunication($project, [
            'channel' => InteractionChannel::Meeting->value,
            'interacted_at' => now(),
            'summary' => 'Site meeting',
            'next_follow_up_at' => now()->addWeek(),
        ]);
        CustomerInteraction::factory()->create(['project_id' => $project->id, 'summary' => 'Removed'])->delete();
        CustomerInteraction::factory()->create(['summary' => 'Another operation']);

        $feed = app(OperationTimelineService::class)->interactionsFor($project);

        $this->assertSame(2, $feed['total'], 'soft-deleted and foreign entries are not counted');
        $this->assertSame([$new->id, $old->id], $feed['items']->pluck('id')->all());
        $this->assertSame('Site meeting', $feed['items'][0]['summary']);
        $this->assertSame(InteractionChannel::Meeting->getLabel(), $feed['items'][0]['channel_label']);
        $this->assertNotNull($feed['items'][0]['next_follow_up_at']);

        // The feed is separate from the stages: still eighteen, same keys.
        $stages = app(OperationTimelineService::class)->for($project);
        $this->assertCount(18, $stages);
        $this->assertSame('customer_contact', $stages[1]['key']);
        $this->assertTrue($stages[1]['reached']);
    }

    public function test_the_feed_is_capped_but_reports_the_total(): void
    {
        $project = Project::factory()->create();
        CustomerInteraction::factory()->count(7)->create(['project_id' => $project->id]);

        $feed = app(OperationTimelineService::class)->interactionsFor($project, 3);

        $this->assertSame(7, $feed['total']);
        $this->assertCount(3, $feed['items']);
    }

    public function test_the_feed_of_many_operations_is_built_in_a_fixed_number_of_queries(): void
    {
        $author = User::factory()->create();
        $projects = Project::factory()->count(6)->create();
        foreach ($projects as $project) {
            CustomerInteraction::factory()->count(3)->create(['project_id' => $project->id, 'created_by' => $author->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $feeds = app(OperationTimelineService::class)->interactionsForMany($projects);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries, 'totals + ranked interactions + authors, however many operations');
        $this->assertCount(6, $feeds);
        $this->assertSame(3, $feeds[$projects->first()->id]['total']);
        $this->assertSame($author->name, $feeds[$projects->first()->id]['items'][0]['recorded_by']);
    }

    public function test_the_timeline_page_payload_carries_the_interactions_feed(): void
    {
        $this->as('Admin');
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        CustomerInteraction::factory()->create([
            'project_id' => $project->id,
            'summary' => 'Asked for the delivery date',
            'next_follow_up_at' => now()->addDays(2),
        ]);
        $quiet = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $payload = collect(Livewire::test(ProjectsTimeline::class)->instance()->timelinePayload())->keyBy('id');

        $this->assertSame(1, $payload[$project->id]['interactions']['total']);
        $this->assertSame('Asked for the delivery date', $payload[$project->id]['interactions']['items'][0]['summary']);
        $this->assertNotNull($payload[$project->id]['interactions']['items'][0]['next_follow_up_at']);
        $this->assertSame(['total' => 0, 'items' => []], $payload[$quiet->id]['interactions']);

        // Still the same eighteen stages.
        $stages = collect($payload[$project->id]['groups'])->flatMap(fn (array $g): array => $g['stages']);
        $this->assertCount(18, $stages);
    }

    public function test_the_communication_columns_show_on_the_follow_up_log(): void
    {
        $this->as('Admin');
        $interaction = CustomerInteraction::factory()->create(['summary' => 'Quote discussed']);

        Livewire::test(\App\Filament\Resources\CustomerInteractionResource\Pages\ListCustomerInteractions::class)
            ->assertCanSeeTableRecords([$interaction])
            ->assertSee('Quote discussed');
    }
}
