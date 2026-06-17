<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\AttachmentCategory;
use App\Filament\Resources\ProjectResource\Pages\AttachmentPersistence;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use App\Services\SalesAlertService;
use App\Services\SalesPipelineService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Self-clearing bell alerts: a red error in the topbar bell whenever a Tender
 * operation has no offer or an In-Hand operation has no SMB, linking to the
 * operation and vanishing the moment the gap is filled.
 */
class OperationAlertTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Sales');

        return $user;
    }

    private function service(): SalesAlertService
    {
        return app(SalesAlertService::class);
    }

    private function alertsOf(User $user, string $type): int
    {
        return $user->fresh()->notifications()
            ->where('data->viewData->alert_type', $type)
            ->count();
    }

    public function test_reconcile_raises_a_missing_offer_alert_for_a_tender(): void
    {
        $sales = $this->sales();
        $tender = Project::factory()->tender()->create();

        $this->service()->reconcileOperationAlerts();

        $this->assertSame(1, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_OFFER));

        $alert = $sales->fresh()->notifications()->first();
        $this->assertSame('danger', $alert->data['status'] ?? null);
        $this->assertSame($tender->id, $alert->data['viewData']['project_id'] ?? null);
        // The action links straight to the operation.
        $this->assertStringContainsString((string) $tender->id, $alert->data['actions'][0]['url'] ?? '');
    }

    public function test_reconcile_raises_a_missing_smb_alert_for_an_in_hand_operation(): void
    {
        $sales = $this->sales();
        Project::factory()->inHand()->create();

        $this->service()->reconcileOperationAlerts();

        $this->assertSame(1, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_SMB));
    }

    public function test_an_in_hand_operation_with_an_smb_is_not_flagged(): void
    {
        $sales = $this->sales();
        $project = Project::factory()->inHand()->create();
        $project->attachments()->create([
            'file_name' => 'smb.pdf',
            'file_path' => "attachments/{$project->id}/submittal/smb.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 1,
            'category' => AttachmentCategory::Submittal->value,
            'uploaded_by' => $sales->id,
        ]);

        $this->service()->reconcileOperationAlerts();

        $this->assertSame(0, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_SMB));
    }

    public function test_reconcile_is_idempotent_and_does_not_duplicate_alerts(): void
    {
        $sales = $this->sales();
        Project::factory()->tender()->create();

        $this->service()->reconcileOperationAlerts();
        $this->service()->reconcileOperationAlerts();
        $this->service()->reconcileOperationAlerts();

        $this->assertSame(1, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_OFFER));
    }

    public function test_attaching_a_priced_offer_clears_the_missing_offer_alert(): void
    {
        $sales = $this->sales();
        $tender = Project::factory()->tender()->create();

        $this->service()->reconcileOperationAlerts();
        $this->assertSame(1, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_OFFER));

        // The observer fires notifyOfferAttached, which clears the alert.
        ProjectOffer::factory()->for($tender)->create(['financial_amount' => 5000]);

        $this->assertSame(0, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_OFFER));
    }

    public function test_a_zero_priced_offer_does_not_clear_the_missing_offer_alert(): void
    {
        $sales = $this->sales();
        $tender = Project::factory()->tender()->create();

        $this->service()->reconcileOperationAlerts();
        ProjectOffer::factory()->for($tender)->create(['financial_amount' => 0]);

        $this->assertSame(1, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_OFFER));
    }

    public function test_uploading_a_submittal_clears_the_missing_smb_alert(): void
    {
        Storage::fake('public');
        $sales = $this->sales();
        $this->actingAs($sales);
        $project = Project::factory()->inHand()->create();

        $this->service()->reconcileOperationAlerts();
        $this->assertSame(1, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_SMB));

        $path = "attachments/{$project->id}/submittal/doc.pdf";
        Storage::disk('public')->put($path, 'PDF');
        AttachmentPersistence::sync($project, [AttachmentCategory::Submittal->value => [$path]]);

        $this->assertSame(0, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_SMB));
    }

    public function test_moving_a_tender_to_in_hand_swaps_the_offer_alert_for_an_smb_alert(): void
    {
        $sales = $this->sales();
        $tender = Project::factory()->tender()->create();
        // Give it an offer so it can move, but no SMB.
        ProjectOffer::factory()->for($tender)->create(['financial_amount' => 5000]);

        $this->service()->reconcileOperationAlerts();
        // The priced offer already cleared the offer alert via the observer.
        $this->assertSame(0, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_OFFER));

        app(SalesPipelineService::class)->moveToInHand($tender->fresh());

        $this->assertSame(0, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_OFFER));
        $this->assertSame(1, $this->alertsOf($sales, SalesAlertService::ALERT_MISSING_SMB));
    }

    public function test_clearing_is_silent_when_no_recipients_hold_the_roles(): void
    {
        // No roles seeded, no users — reconcile must be a harmless no-op.
        Project::factory()->tender()->create();
        Project::factory()->inHand()->create();

        $this->service()->reconcileOperationAlerts();

        $this->assertDatabaseCount('notifications', 0);
    }
}
