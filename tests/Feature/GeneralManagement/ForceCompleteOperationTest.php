<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\ProjectStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\ActiveProjectResource\Pages\ListActiveProjects;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OperationLifecycleService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * إنهاء استثنائي (Final_Modifications fix wave B / ruling G1): the General
 * Manager (and Admin) can end an operation that cannot pass the completion gate
 * — a legacy, in-flight one — by giving a mandatory reason that is recorded.
 */
class ForceCompleteOperationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'operations.force_complete';

    private OperationLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->service = app(OperationLifecycleService::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** An in-flight operation the gate would refuse: a running order, nothing delivered. */
    private function legacyOperation(): Project
    {
        $project = Project::factory()->active()->create(['name' => 'Legacy operation']);
        WorkOrder::factory()->create([
            'project_id' => $project->id,
            'status' => WorkOrderStatus::InProgress,
            'manufacturing_finished_at' => null,
        ]);

        return $project;
    }

    // ---------------------------------------------------------------- RBAC

    public function test_only_the_general_manager_and_admin_hold_the_permission(): void
    {
        foreach (['General_Manager', 'Admin'] as $role) {
            $this->assertTrue($this->userWithRole($role)->can(self::PERMISSION), $role);
        }

        foreach (['Factory_Manager', 'Sales', 'Sales_Manager', 'Sales_Officer', 'Sales_Head', 'Finance', 'Timeline_Coordinator'] as $role) {
            $this->assertFalse(Role::findByName($role, 'web')->hasPermissionTo(self::PERMISSION), $role);
        }
    }

    public function test_the_permission_has_a_label_in_both_locales(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([
                'resources.permissions.operations.force_complete',
                'resources.operation_force_complete.action',
                'resources.operation_force_complete.heading',
                'resources.operation_force_complete.description',
                'resources.operation_force_complete.reason',
                'resources.operation_force_complete.reason_help',
                'resources.operation_force_complete.submit',
                'resources.operation_force_complete.notification',
                'resources.activities.events.force_completed',
                'resources.activities.field_labels.force_complete_reason',
                'errors.operations.force_complete_forbidden',
                'errors.operations.force_complete_reason_required',
            ] as $key) {
                $this->assertNotSame($key, __($key), "Missing {$locale} key: {$key}");
            }
        }
    }

    // ------------------------------------------------------------ service

    public function test_it_completes_an_operation_the_gate_would_refuse_and_records_the_reason(): void
    {
        $project = $this->legacyOperation();
        $manager = $this->userWithRole('General_Manager');

        $this->assertNotSame([], $this->service->completionBlockers($project), 'the gate refuses it');

        $this->service->forceComplete($project, '  Delivered in 2025 before the system tracked it  ', $manager);

        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);

        $entry = Activity::query()->where('event', 'force_completed')->sole();
        $this->assertSame(Project::class, $entry->subject_type);
        $this->assertSame($project->id, (int) $entry->subject_id);
        $this->assertSame($manager->id, (int) $entry->causer_id);
        $this->assertStringContainsString('Delivered in 2025', (string) $entry->description);
        $this->assertSame('Delivered in 2025 before the system tracked it', $entry->properties['attributes']['force_complete_reason']);
        $this->assertSame('completed', $entry->properties['attributes']['status']);
        $this->assertSame('in_progress', $entry->properties['old']['status']);
        // What was waived is kept as evidence.
        $this->assertNotEmpty($entry->properties['bypassed_blockers']);
    }

    public function test_it_also_ends_an_operation_that_is_on_hold(): void
    {
        $project = $this->legacyOperation();
        $this->service->putOnHold($project);

        $this->service->forceComplete($project->fresh(), 'Closed by management', $this->userWithRole('Admin'));

        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_a_reason_is_mandatory(): void
    {
        $project = $this->legacyOperation();

        foreach (['', '   '] as $blank) {
            try {
                $this->service->forceComplete($project, $blank, $this->userWithRole('General_Manager'));
                $this->fail('A blank reason must be refused.');
            } catch (DomainException $e) {
                $this->assertSame(__('errors.operations.force_complete_reason_required'), $e->getMessage());
            }
        }

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
        $this->assertSame(0, Activity::query()->where('event', 'force_completed')->count());
    }

    public function test_the_service_refuses_a_user_without_the_permission(): void
    {
        $project = $this->legacyOperation();

        try {
            $this->service->forceComplete($project, 'Because I said so', $this->userWithRole('Factory_Manager'));
            $this->fail('A user without operations.force_complete must be refused.');
        } catch (DomainException $e) {
            $this->assertSame(__('errors.operations.force_complete_forbidden'), $e->getMessage());
        }

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
        $this->assertSame(0, Activity::query()->where('event', 'force_completed')->count());
    }

    public function test_only_an_active_or_on_hold_operation_can_be_ended(): void
    {
        $draft = Project::factory()->draft()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(__('errors.operations.illegal_transition', [
            'current' => 'draft',
            'allowed' => 'in_progress, on_hold',
        ]));

        $this->service->forceComplete($draft, 'Reason', $this->userWithRole('General_Manager'));
    }

    // ----------------------------------------------------------- Filament

    public function test_the_general_manager_completes_an_operation_by_exception_from_the_list(): void
    {
        $project = $this->legacyOperation();
        $manager = $this->userWithRole('General_Manager');

        Livewire::actingAs($manager)
            ->test(ListActiveProjects::class)
            ->assertTableActionVisible('force_complete', $project)
            ->callTableAction('force_complete', $project, data: ['reason' => 'Legacy operation, no manufacturing records'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('resources.operation_force_complete.notification'));

        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);

        $entry = Activity::query()->where('event', 'force_completed')->sole();
        $this->assertSame($manager->id, (int) $entry->causer_id);
        $this->assertSame('Legacy operation, no manufacturing records', $entry->properties['attributes']['force_complete_reason']);
    }

    public function test_the_action_cannot_be_confirmed_without_a_reason(): void
    {
        $project = $this->legacyOperation();

        Livewire::actingAs($this->userWithRole('General_Manager'))
            ->test(ListActiveProjects::class)
            ->callTableAction('force_complete', $project, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
        $this->assertSame(0, Activity::query()->where('event', 'force_completed')->count());
    }

    public function test_everyone_else_never_sees_the_action(): void
    {
        $project = $this->legacyOperation();

        foreach (['Factory_Manager', 'Sales', 'Sales_Manager', 'Sales_Officer', 'Sales_Head'] as $role) {
            Livewire::actingAs($this->userWithRole($role))
                ->test(ListActiveProjects::class)
                ->assertCanSeeTableRecords([$project])
                ->assertTableActionHidden('force_complete', $project);
        }

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
    }

    // ---------------------------------------------------------- migration

    public function test_the_migration_grants_general_manager_and_admin_and_is_idempotent(): void
    {
        $permission = Permission::findByName(self::PERMISSION, 'web');

        // Reproduce production: the roles existed before the permission did.
        foreach (['General_Manager', 'Admin'] as $role) {
            Role::findByName($role, 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->userWithRole('General_Manager')->can(self::PERMISSION));

        $migration = require database_path('migrations/2026_11_19_101000_grant_force_complete_permission.php');
        $migration->up();
        $migration->up(); // idempotent

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($this->userWithRole('General_Manager')->can(self::PERMISSION));
        $this->assertTrue($this->userWithRole('Admin')->can(self::PERMISSION));
        $this->assertFalse($this->userWithRole('Factory_Manager')->can(self::PERMISSION));

        $this->assertSame(1, Permission::query()->where('name', self::PERMISSION)->count());
        $this->assertSame(
            1,
            DB::table('role_has_permissions')
                ->where('permission_id', $permission->id)
                ->where('role_id', Role::findByName('General_Manager', 'web')->id)
                ->count(),
        );
    }

    public function test_the_migration_creates_the_permission_when_it_is_missing(): void
    {
        Permission::findByName(self::PERMISSION, 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_11_19_101000_grant_force_complete_permission.php');
        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($this->userWithRole('General_Manager')->can(self::PERMISSION));
    }
}
