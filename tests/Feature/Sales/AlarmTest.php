<?php

namespace Tests\Feature\Sales;

use App\Models\Project;
use App\Services\SalesPipelineService;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlarmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_set_alarm_persists_time_and_note(): void
    {
        $project = Project::factory()->tender()->create(['alarm_at' => null, 'alarm_note' => null]);
        $when = Carbon::parse('2026-06-15 10:30:00');

        app(SalesPipelineService::class)->setAlarm($project, $when, 'Follow up with consultant');

        $fresh = $project->fresh();
        $this->assertTrue($when->equalTo($fresh->alarm_at));
        $this->assertSame('Follow up with consultant', $fresh->alarm_note);
    }

    public function test_clear_alarm_nulls_both_fields(): void
    {
        $project = Project::factory()->tender()->create([
            'alarm_at' => now(),
            'alarm_note' => 'something',
        ]);

        app(SalesPipelineService::class)->clearAlarm($project);

        $fresh = $project->fresh();
        $this->assertNull($fresh->alarm_at);
        $this->assertNull($fresh->alarm_note);
    }
}
