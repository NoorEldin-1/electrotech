<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Models\Project;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Final_Modifications Task 3 — engineer contact fields over the REST API.
 */
class ProjectEngineerContactApiTest extends ApiTestCase
{
    public function test_it_creates_an_operation_with_engineer_contact_fields(): void
    {
        $response = $this->actingAsApi($this->userWith(['projects.create']))
            ->apiPost(self::BASE.'/projects', [
                'name' => 'Substation C',
                'client_name' => 'Delta Contracting',
                'engineer_name' => 'Eng. Mona',
                'engineer_phone' => '01012345678',
                'engineer_email' => 'mona@example.com',
                'engineer_office' => 'Nile Office',
                'engineer_site' => 'New Cairo',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.engineer_phone', '01012345678');
        $response->assertJsonPath('data.engineer_email', 'mona@example.com');
        $response->assertJsonPath('data.engineer_office', 'Nile Office');
        $response->assertJsonPath('data.engineer_site', 'New Cairo');

        $this->assertDatabaseHas('projects', [
            'name' => 'Substation C',
            'engineer_email' => 'mona@example.com',
            'engineer_site' => 'New Cairo',
        ]);
    }

    public function test_it_updates_engineer_contact_fields(): void
    {
        $project = Project::factory()->create();

        $this->actingAsApi($this->userWith(['projects.edit']))
            ->apiPatch(self::BASE.'/projects/'.$project->id, [
                'engineer_phone' => '0223456789',
                'engineer_office' => 'Updated Office',
            ])
            ->assertOk()
            ->assertJsonPath('data.engineer_phone', '0223456789')
            ->assertJsonPath('data.engineer_office', 'Updated Office');
    }

    public function test_it_rejects_an_invalid_engineer_email(): void
    {
        $user = $this->userWith(['projects.create', 'projects.edit']);
        $project = Project::factory()->create();

        $this->actingAsApi($user)
            ->apiPost(self::BASE.'/projects', [
                'name' => 'X',
                'client_name' => 'Y',
                'engineer_email' => 'nope',
            ])
            ->assertUnprocessable();

        $this->actingAsApi($user)
            ->apiPatch(self::BASE.'/projects/'.$project->id, ['engineer_email' => 'nope'])
            ->assertUnprocessable();
    }
}
