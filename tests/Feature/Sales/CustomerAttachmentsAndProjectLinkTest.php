<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Filament\RelationManagers\AccountEntriesRelationManager;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\SupplierResource\Pages\EditSupplier;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Tests\TestCase;

/**
 * Covers the two sales modifications:
 *  - Customers gain a polymorphic attachments section (multiple files).
 *  - Projects pick their customer from a relationship select instead of a
 *    free-text name, while the legacy client_name column stays in sync.
 */
class CustomerAttachmentsAndProjectLinkTest extends TestCase
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

    public function test_customer_create_and_edit_forms_render(): void
    {
        Livewire::test(CreateCustomer::class)->assertOk();

        $customer = Customer::factory()->create();
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->assertOk();
    }

    /**
     * Regression: the shared account-statement relation manager must be
     * registered as a Livewire component. Gating getRelations() on auth() left
     * it unregistered (registration runs at boot, before the auth guard is
     * resolved), so the statement tab threw
     * "Unable to find component: [...account-entries-relation-manager]"
     * on the customer/supplier edit page.
     */
    public function test_account_statement_relation_manager_is_registered(): void
    {
        $class = app(ComponentRegistry::class)
            ->getClass('app.filament.relation-managers.account-entries-relation-manager');

        $this->assertSame(AccountEntriesRelationManager::class, $class);
    }

    public function test_statement_tab_visibility_follows_permission(): void
    {
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        // Admin (from setUp) holds both statement permissions.
        $this->assertTrue(AccountEntriesRelationManager::canViewForRecord($customer, EditCustomer::class));
        $this->assertTrue(AccountEntriesRelationManager::canViewForRecord($supplier, EditSupplier::class));

        // A user without the permissions cannot see the tab.
        $this->actingAs(User::factory()->create());
        $this->assertFalse(AccountEntriesRelationManager::canViewForRecord($customer, EditCustomer::class));
        $this->assertFalse(AccountEntriesRelationManager::canViewForRecord($supplier, EditSupplier::class));
    }

    public function test_project_create_form_renders_with_customer_select(): void
    {
        Livewire::test(CreateProject::class)->assertOk();
    }

    /**
     * The project attachments section must include the customer-acceptance
     * dropzone (so the acceptance document can be managed directly on the
     * project) and must NOT include the customer-only documents bucket.
     */
    public function test_project_attachment_schema_includes_acceptance_and_excludes_customer_documents(): void
    {
        $method = new \ReflectionMethod(ProjectResource::class, 'attachmentCategorySchema');
        $method->setAccessible(true);

        $names = array_map(
            fn ($component) => $component->getName(),
            $method->invoke(null),
        );

        $this->assertContains('attachments_customer_acceptance', $names);
        $this->assertNotContains('attachments_customer_document', $names);
    }

    public function test_project_created_via_form_links_customer_and_mirrors_client_name(): void
    {
        $customer = Customer::factory()->create(['name' => 'Nile Contracting']);

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'New Substation',
                'customer_id' => $customer->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'New Substation',
            'customer_id' => $customer->id,
            'client_name' => 'Nile Contracting',
        ]);
    }

    public function test_editing_project_customer_updates_client_name(): void
    {
        $customer = Customer::factory()->create(['name' => 'Delta Power']);
        $project = Project::factory()->create([
            'client_name' => 'Old Name',
            'customer_id' => null,
        ]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['customer_id' => $customer->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Delta Power', $project->fresh()->client_name);
        $this->assertSame($customer->id, $project->fresh()->customer_id);
    }
}
