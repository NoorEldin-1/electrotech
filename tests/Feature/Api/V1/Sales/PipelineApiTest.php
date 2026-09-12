<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Enums\AttachmentCategory;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectOffer;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * The pipeline transitions. Every rule asserted here lives in
 * SalesPipelineService and is shared with the Filament panel — these tests
 * prove the API reaches the same rules, not that it re-implements them.
 */
class PipelineApiTest extends ApiTestCase
{
    // -------------------------------------------------------- Draft → Tender

    public function test_it_moves_a_draft_with_an_offer_to_tender(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Draft]);
        ProjectOffer::factory()->create(['project_id' => $project->id]);

        $response = $this->actingAsApi($this->userWith(['projects.move_to_tender']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-tender');

        $response->assertOk();
        // The transition answers with the operation in its new state, so the
        // client renders the result without a second request.
        $response->assertJsonPath('data.status.value', 'tender');
    }

    public function test_a_draft_with_no_offer_cannot_move_to_tender(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Draft]);

        $response = $this->actingAsApi($this->userWith(['projects.move_to_tender']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-tender');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');

        // The message comes from the service and is written for a person, so
        // the client may show it directly.
        $this->assertStringContainsString('offer', $response->json('error.message'));
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    // ------------------------------------------------------ Tender → In Hand

    public function test_moving_to_in_hand_derives_the_smb_status_from_the_document(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Tender]);
        $user = $this->userWith(['projects.move_to_inhand']);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-in-hand')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'in_hand')
            // No Submittal on file yet.
            ->assertJsonPath('data.smb_status', 'pending')
            ->assertJsonPath('data.has_smb', false);
    }

    public function test_the_smb_status_is_submitted_when_the_document_is_on_file(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Tender]);
        $user = $this->userWith(['projects.move_to_inhand']);

        $project->attachments()->create([
            'file_name' => 'submittal.pdf',
            'file_path' => 'attachments/1/submittal/a.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 10,
            'category' => AttachmentCategory::Submittal->value,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-in-hand')
            ->assertOk()
            ->assertJsonPath('data.smb_status', 'submitted')
            ->assertJsonPath('data.has_smb', true);
    }

    public function test_an_operation_cannot_skip_a_stage(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Draft]);

        $response = $this->actingAsApi($this->userWith(['projects.move_to_inhand']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-in-hand');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    // ------------------------------------------------------ In Hand → Active

    public function test_moving_to_active_requires_both_signals(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::InHand,
            'acceptance_email_at' => null,
            'manager_approved_at' => null,
        ]);

        $user = $this->userWith(['projects.move_to_active', 'projects.manager_approve', 'projects.edit']);

        // 1. Neither signal: refused for the acceptance email.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-active')
            ->assertStatus(422);

        // 2. Acceptance email only: still refused, now for the approval.
        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/projects/'.$project->id, ['acceptance_email_at' => now()->toDateString()])
            ->assertOk();

        $response = $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-active');
        $response->assertStatus(422);
        $this->assertStringContainsString('approval', $response->json('error.message'));

        // 3. Both on file: the move goes through.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/manager-approve')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'in_hand');

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-active')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'in_progress');
    }

    public function test_moving_to_active_marks_the_latest_offer_as_winning(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::InHand,
            'acceptance_email_at' => now(),
            'manager_approved_at' => now(),
        ]);

        $older = ProjectOffer::factory()->create(['project_id' => $project->id, 'version' => 1]);
        $newer = ProjectOffer::factory()->create(['project_id' => $project->id, 'version' => 2]);

        $this->actingAsApi($this->userWith(['projects.move_to_active']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-active')
            ->assertOk();

        // The price the operation was sold at is what the cost centre is
        // measured against, so exactly one offer must carry the flag.
        $this->assertTrue((bool) $newer->fresh()->is_winning);
        $this->assertFalse((bool) $older->fresh()->is_winning);
    }

    // ------------------------------------------------------------------ Lost

    public function test_it_cancels_an_operation_to_lost_with_a_reason(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Tender]);

        $response = $this->actingAsApi($this->userWith(['projects.cancel_to_lost']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/cancel-to-lost', [
                'lost_reason' => 'high_price',
                'lost_reason_note' => '12% above the winning bid',
                'winning_competitor' => 'Schneider',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status.value', 'lost');
        $response->assertJsonPath('data.lost_reason.value', 'high_price');
        $response->assertJsonPath('data.winning_competitor', 'Schneider');
    }

    public function test_cancelling_to_lost_requires_a_reason_from_the_enum(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Tender]);
        $user = $this->userWith(['projects.cancel_to_lost']);

        // Without a reason there is nothing for the year-end loss analysis to
        // aggregate, which is the only reason a lost operation is kept.
        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/cancel-to-lost', [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['lost_reason']]]);

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects/'.$project->id.'/cancel-to-lost', ['lost_reason' => 'because'])
            ->assertStatus(422);
    }

    public function test_an_active_operation_cannot_be_cancelled_to_lost(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::InProgress]);

        $response = $this->actingAsApi($this->userWith(['projects.cancel_to_lost']))
            ->apiPost(self::BASE.'/projects/'.$project->id.'/cancel-to-lost', ['lost_reason' => 'high_price']);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'business_rule_violated');
    }

    // --------------------------------------------------------------- alarms

    public function test_it_sets_and_clears_a_reminder(): void
    {
        $project = Project::factory()->create();
        $user = $this->userWith(['projects.set_alarm']);

        $this->actingAsApi($user)
            ->apiJson('PUT', self::BASE.'/projects/'.$project->id.'/alarm', [
                'alarm_at' => now()->addWeek()->toIso8601String(),
                'alarm_note' => 'Call the consultant',
            ])
            ->assertOk()
            ->assertJsonPath('data.alarm_note', 'Call the consultant');

        $this->assertNotNull($project->fresh()->alarm_at);

        $this->actingAsApi($user)
            ->apiDelete(self::BASE.'/projects/'.$project->id.'/alarm')
            ->assertOk()
            ->assertJsonPath('data.alarm_at', null);
    }

    public function test_a_reminder_in_the_past_is_rejected(): void
    {
        $project = Project::factory()->create();

        // It would fire immediately and never be seen, which reads to the user
        // as the feature being broken.
        $this->actingAsApi($this->userWith(['projects.set_alarm']))
            ->apiJson('PUT', self::BASE.'/projects/'.$project->id.'/alarm', [
                'alarm_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['alarm_at']]]);
    }

    // ------------------------------------------------------------------ RBAC

    public function test_each_transition_has_its_own_permission(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Tender]);

        // projects.edit is deliberately not enough: a user who may correct a
        // typo is not thereby allowed to push a job into manufacturing.
        $editor = $this->userWith(['projects.view', 'projects.edit']);

        foreach ([
            'move-to-tender',
            'move-to-in-hand',
            'move-to-active',
            'manager-approve',
            'cancel-to-lost',
        ] as $transition) {
            $this->actingAsApi($editor)
                ->apiPost(self::BASE.'/projects/'.$project->id.'/'.$transition, ['lost_reason' => 'high_price'])
                ->assertForbidden();
        }

        $this->actingAsApi($editor)
            ->apiJson('PUT', self::BASE.'/projects/'.$project->id.'/alarm', [
                'alarm_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertForbidden();
    }

    public function test_transitions_require_a_token(): void
    {
        $project = Project::factory()->create();

        $this->apiPost(self::BASE.'/projects/'.$project->id.'/move-to-tender')
            ->assertUnauthorized();
    }
}
