<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\AttachmentCategory;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSurveyTest extends TestCase
{
    use RefreshDatabase;

    public function test_survey_is_linked_to_operation(): void
    {
        $project = Project::factory()->active()->create();

        $survey = SiteSurvey::factory()->create([
            'project_id' => $project->id,
            'measurements' => '3000x2000x800 mm',
        ]);

        $this->assertSame($project->id, $survey->project_id);
        $this->assertSame($project->id, $survey->project->id);
        $this->assertSame('3000x2000x800 mm', $survey->measurements);
    }

    public function test_site_measurement_attachment_category_exists_and_resolves(): void
    {
        $this->assertContains('site_measurement', array_map(fn ($c) => $c->value, AttachmentCategory::cases()));

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $label = AttachmentCategory::SiteMeasurement->getLabel();
            $this->assertNotSame('resources.enums.attachment_category.site_measurement', $label);
        }
    }

    public function test_access_is_gated_by_policy(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $survey = SiteSurvey::factory()->create();

        $tech = User::factory()->create();
        $tech->assignRole('Technical_Office');
        $this->assertTrue($tech->can('update', $survey));

        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->assertFalse($sales->can('update', $survey));
    }
}
