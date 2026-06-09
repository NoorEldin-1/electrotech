<?php

namespace Tests\Feature\Sales;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slide 6: a new operation must land in Tender (المناقصات), not "Draft".
 */
class DefaultStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_project_without_status_defaults_to_tender(): void
    {
        $project = Project::factory()->create(['status' => null]);

        $this->assertSame(ProjectStatus::Tender, $project->fresh()->status);
    }

    public function test_an_explicit_status_is_never_overridden(): void
    {
        $project = Project::factory()->draft()->create();

        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }
}
