<?php

namespace Tests\Feature\Sales;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ActivitiesRelationManager;
use App\Models\Project;
use App\Models\ProjectOffer;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Slide 9: a HISTORY to follow the operation. The Project model already logs
 * activity; the relation manager surfaces it inline behind projects.view_history.
 */
class ProjectHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_status_change_is_recorded_in_the_operation_history(): void
    {
        $project = Project::factory()->tender()->create();

        $project->update(['status' => ProjectStatus::InHand]);

        $this->assertTrue(
            $project->activities()->where('event', 'updated')->exists(),
            'Status change should appear in the activity log.'
        );
    }

    public function test_history_is_gated_by_view_history_permission(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');

        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');

        $this->assertTrue($sales->can('projects.view_history'));
        $this->assertFalse($warehouse->can('projects.view_history'));
    }

    /**
     * Slide 6: the History folds in lifecycle events that live on related
     * records — here, "when the financial offer was raised" — not just the
     * project's own changes.
     */
    public function test_history_folds_in_related_offer_activity(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);

        $project = Project::factory()->create();
        $offer = $project->offers()->create(['financial_amount' => 1000]);

        $offerActivity = Activity::query()
            ->where('subject_type', ProjectOffer::class)
            ->where('subject_id', $offer->id)
            ->firstOrFail();

        Livewire::test(ActivitiesRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ])->assertCanSeeTableRecords([$offerActivity]);
    }
}
