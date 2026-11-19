<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Final_Modifications_Plan 1.1 — customer communication over the API: the
 * summary / next follow-up fields and the `interactions` feed of the
 * operation timeline.
 */
class CustomerCommunicationApiTest extends ApiTestCase
{
    public function test_a_communication_is_logged_with_summary_and_next_follow_up(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress, 'customer_id' => $customer->id]);
        $user = $this->userWith(['customer_interactions.view', 'customer_interactions.manage']);

        $created = $this->actingAsApi($user)->apiPost(self::BASE.'/customer-interactions', [
            'project_id' => $project->id,
            'type' => 'contact',
            'channel' => 'meeting',
            'interacted_at' => '2026-09-01 10:00:00',
            'summary' => 'Agreed the drawing revision',
            'notes' => 'Customer will reply by Sunday',
            'next_follow_up_at' => '2026-09-06 09:00:00',
        ]);

        $created->assertCreated();
        $created->assertJsonPath('data.customer_id', $customer->id);
        $created->assertJsonPath('data.channel.value', 'meeting');
        $created->assertJsonPath('data.summary', 'Agreed the drawing revision');
        $this->assertNotNull($created->json('data.next_follow_up_at'));

        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/customer-interactions/'.$created->json('data.id'), ['summary' => 'Revision signed off'])
            ->assertOk()
            ->assertJsonPath('data.summary', 'Revision signed off');
    }

    public function test_the_operation_timeline_has_an_interactions_feed_beside_the_eighteen_stages(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);
        $manager = $this->userWith(['customer_interactions.view', 'customer_interactions.manage']);

        $this->actingAsApi($manager)->apiPost(self::BASE.'/customer-interactions', [
            'project_id' => $project->id,
            'type' => 'contact',
            'channel' => 'call',
            'interacted_at' => '2026-09-01 10:00:00',
            'summary' => 'First call',
        ])->assertCreated();

        $this->actingAsApi($manager)->apiPost(self::BASE.'/customer-interactions', [
            'project_id' => $project->id,
            'type' => 'contact',
            'channel' => 'message',
            'interacted_at' => '2026-09-03 12:00:00',
            'summary' => 'Sent the quote',
            'next_follow_up_at' => '2026-09-08 09:00:00',
        ])->assertCreated();

        $response = $this->actingAsApi($this->userWith(['operations.timeline']))
            ->apiGet(self::BASE."/projects/{$project->id}/timeline");

        $response->assertOk();
        $response->assertJsonCount(18, 'data.stages');
        $response->assertJsonPath('data.interactions.total', 2);
        $response->assertJsonCount(2, 'data.interactions.items');
        // Newest first.
        $response->assertJsonPath('data.interactions.items.0.summary', 'Sent the quote');
        $response->assertJsonPath('data.interactions.items.0.channel', 'message');
        $response->assertJsonPath('data.interactions.items.1.summary', 'First call');
        $this->assertNotNull($response->json('data.interactions.items.0.next_follow_up_at'));
        $this->assertNull($response->json('data.interactions.items.1.next_follow_up_at'));

        // The communication also moves the existing "customer contact" stage.
        $stages = collect($response->json('data.stages'))->keyBy('key');
        $this->assertTrue($stages['customer_contact']['reached']);
    }

    public function test_an_operation_without_communication_has_an_empty_feed(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $response = $this->actingAsApi($this->userWith(['operations.timeline']))
            ->apiGet(self::BASE."/projects/{$project->id}/timeline");

        $response->assertOk();
        $response->assertJsonPath('data.interactions.total', 0);
        $response->assertJsonPath('data.interactions.items', []);
    }
}
