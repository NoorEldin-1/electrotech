<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Final_Modifications Task 3 — engineer contact block on the project form.
 */
class EngineerContactFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_engineer_contact_fields_are_saved_from_the_create_form(): void
    {
        $customer = Customer::factory()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'Engineer Block Project',
                'customer_id' => $customer->id,
                'engineer_name' => 'Eng. Mona',
                'engineer_phone' => '01012345678',
                'engineer_email' => 'mona@example.com',
                'engineer_office' => 'Nile Office',
                'engineer_site' => 'New Cairo',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Engineer Block Project',
            'engineer_name' => 'Eng. Mona',
            'engineer_phone' => '01012345678',
            'engineer_email' => 'mona@example.com',
            'engineer_office' => 'Nile Office',
            'engineer_site' => 'New Cairo',
        ]);
    }

    public function test_engineer_contact_fields_are_updated_from_the_edit_form(): void
    {
        $project = Project::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'engineer_phone' => '0223456789',
                'engineer_email' => 'new@example.com',
                'engineer_office' => 'Updated Office',
                'engineer_site' => 'Alexandria',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'engineer_phone' => '0223456789',
            'engineer_email' => 'new@example.com',
            'engineer_office' => 'Updated Office',
            'engineer_site' => 'Alexandria',
        ]);
    }

    public function test_engineer_email_must_be_a_valid_email_on_the_form(): void
    {
        $project = Project::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['engineer_email' => 'not-an-email'])
            ->call('save')
            ->assertHasFormErrors(['engineer_email']);
    }
}
