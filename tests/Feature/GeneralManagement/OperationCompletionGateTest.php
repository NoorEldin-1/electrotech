<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use App\Enums\DeliveryVoucherStatus;
use App\Enums\ItemType;
use App\Enums\ProjectStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\ActiveProjectResource\Pages\ListActiveProjects;
use App\Models\DeliveryVoucher;
use App\Models\Item;
use App\Models\Project;
use App\Models\QualitySheet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OperationLifecycleService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sales can no longer end an operation (Final_Modifications Task 2 / R4): it
 * ends only after manufacturing finish (factory manager), an approved quality
 * sheet and activated delivery vouchers covering everything that was ordered.
 */
class OperationCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    private OperationLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->service = app(OperationLifecycleService::class);
    }

    // ------------------------------------------------------------- helpers

    private function finishedGood(): Item
    {
        return Item::factory()->create(['type' => ItemType::FinishedGood, 'unit_cost' => 100]);
    }

    /**
     * A manufacturing order that has been finished by the factory manager and
     * has an approved quality sheet, producing $quantity of $item.
     */
    private function finishedOrder(Project $project, Item $item, float $quantity = 10): WorkOrder
    {
        $order = WorkOrder::factory()->approved()->create([
            'project_id' => $project->id,
            'status' => WorkOrderStatus::Completed,
            'planned_quantity' => $quantity,
            'manufacturing_finished_at' => now(),
        ]);
        $order->outputs()->create(['item_id' => $item->id, 'planned_quantity' => $quantity]);
        QualitySheet::factory()->approved()->create(['work_order_id' => $order->id]);

        return $order;
    }

    private function voucher(Project $project, Item $item, float $quantity, DeliveryVoucherStatus $status = DeliveryVoucherStatus::Active): DeliveryVoucher
    {
        $voucher = DeliveryVoucher::factory()->create([
            'project_id' => $project->id,
            'status' => $status,
        ]);
        $voucher->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => 100]);

        return $voucher;
    }

    /** An active operation that satisfies all three conditions. */
    private function readyOperation(): array
    {
        $project = Project::factory()->active()->create(['name' => 'Gate operation']);
        $item = $this->finishedGood();
        $order = $this->finishedOrder($project, $item, 10);
        $this->voucher($project, $item, 10);

        return [$project, $item, $order];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------------------ the gate

    public function test_a_finished_inspected_and_delivered_operation_can_be_completed(): void
    {
        [$project] = $this->readyOperation();

        $this->assertSame([], $this->service->completionBlockers($project));
        $this->assertTrue($this->service->canComplete($project));

        $this->service->markCompleted($project);

        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_an_on_hold_operation_that_is_ready_can_still_be_completed(): void
    {
        [$project] = $this->readyOperation();
        $this->service->putOnHold($project);

        $this->service->markCompleted($project->fresh());

        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_an_unfinished_manufacturing_order_blocks_completion(): void
    {
        [$project, , $order] = $this->readyOperation();
        $order->update(['manufacturing_finished_at' => null]);

        $blockers = $this->service->completionBlockers($project);

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString($order->wo_number, $blockers[0]);

        try {
            $this->service->markCompleted($project);
            $this->fail('An operation with an unfinished order must not complete.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString($order->wo_number, $e->getMessage());
        }

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
    }

    public function test_every_order_must_be_finished_not_just_one(): void
    {
        [$project, $item] = $this->readyOperation();
        $second = $this->finishedOrder($project, $item, 0);
        $second->update(['manufacturing_finished_at' => null, 'status' => WorkOrderStatus::InProgress]);

        $blockers = $this->service->completionBlockers($project);

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString($second->wo_number, $blockers[0]);
    }

    public function test_a_missing_quality_sheet_blocks_completion(): void
    {
        [$project, , $order] = $this->readyOperation();
        $order->qualitySheets()->forceDelete();

        $blockers = $this->service->completionBlockers($project);

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString($order->wo_number, $blockers[0]);
        $this->assertFalse($this->service->canComplete($project));

        $this->expectException(\DomainException::class);
        $this->service->markCompleted($project);
    }

    public function test_a_sheet_that_is_only_drafted_or_filled_is_not_a_pass(): void
    {
        [$project, , $order] = $this->readyOperation();
        $order->qualitySheets()->forceDelete();

        QualitySheet::factory()->create(['work_order_id' => $order->id]);
        $this->assertCount(1, $this->service->completionBlockers($project));

        QualitySheet::factory()->qaFilled()->create(['work_order_id' => $order->id]);
        $this->assertCount(1, $this->service->completionBlockers($project));

        QualitySheet::factory()->approved()->create(['work_order_id' => $order->id]);
        $this->assertSame([], $this->service->completionBlockers($project));
    }

    public function test_no_delivery_voucher_blocks_completion(): void
    {
        $project = Project::factory()->active()->create();
        $this->finishedOrder($project, $this->finishedGood(), 10);

        $blockers = $this->service->completionBlockers($project);

        $this->assertCount(1, $blockers);
        $this->assertSame(__('errors.operations.blockers.no_active_delivery'), $blockers[0]);

        $this->expectException(\DomainException::class);
        $this->service->markCompleted($project);
    }

    public function test_draft_pending_and_cancelled_vouchers_deliver_nothing(): void
    {
        $project = Project::factory()->active()->create();
        $item = $this->finishedGood();
        $this->finishedOrder($project, $item, 10);

        $this->voucher($project, $item, 10, DeliveryVoucherStatus::Draft);
        $this->voucher($project, $item, 10, DeliveryVoucherStatus::PendingApproval);
        $this->voucher($project, $item, 10, DeliveryVoucherStatus::Cancelled);

        $this->assertSame(
            [__('errors.operations.blockers.no_active_delivery')],
            $this->service->completionBlockers($project),
        );
    }

    public function test_a_partial_delivery_blocks_until_the_rest_goes_out(): void
    {
        $project = Project::factory()->active()->create();
        $item = $this->finishedGood();
        $this->finishedOrder($project, $item, 10);
        $this->voucher($project, $item, 6);

        $blockers = $this->service->completionBlockers($project);

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString($item->name, $blockers[0]);
        $this->assertStringContainsString('6', $blockers[0]);
        $this->assertStringContainsString('10', $blockers[0]);

        // A second activated voucher delivers the remaining 4.
        $this->voucher($project, $item, 4);

        $this->assertSame([], $this->service->completionBlockers($project));
        $this->service->markCompleted($project);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_every_product_of_a_multi_output_order_must_be_delivered(): void
    {
        $project = Project::factory()->active()->create();
        $first = $this->finishedGood();
        $second = $this->finishedGood();

        $order = $this->finishedOrder($project, $first, 6);
        $order->outputs()->create(['item_id' => $second->id, 'planned_quantity' => 4]);

        $this->voucher($project, $first, 6);

        $blockers = $this->service->completionBlockers($project);
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString($second->name, $blockers[0]);

        $this->voucher($project, $second, 4);
        $this->assertSame([], $this->service->completionBlockers($project));
    }

    public function test_deliveries_of_another_operation_do_not_count(): void
    {
        $project = Project::factory()->active()->create();
        $other = Project::factory()->active()->create();
        $item = $this->finishedGood();
        $this->finishedOrder($project, $item, 10);
        $this->voucher($other, $item, 10);

        $this->assertSame(
            [__('errors.operations.blockers.no_active_delivery')],
            $this->service->completionBlockers($project),
        );
    }

    public function test_all_blockers_are_listed_together(): void
    {
        $project = Project::factory()->active()->create(['name' => 'Everything missing']);
        $order = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'status' => WorkOrderStatus::InProgress,
            'manufacturing_finished_at' => null,
        ]);

        // Not finished + no sheet + no delivery.
        $this->assertCount(3, $this->service->completionBlockers($project));

        try {
            $this->service->markCompleted($project);
            $this->fail('Nothing is done yet.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Everything missing', $e->getMessage());
            $this->assertStringContainsString($order->wo_number, $e->getMessage());
            $this->assertStringContainsString(__('errors.operations.blockers.no_active_delivery'), $e->getMessage());
        }
    }

    public function test_cancelled_orders_are_ignored(): void
    {
        [$project] = $this->readyOperation();
        WorkOrder::factory()->create([
            'project_id' => $project->id,
            'status' => WorkOrderStatus::Cancelled,
            'manufacturing_finished_at' => null,
        ]);

        $this->assertSame([], $this->service->completionBlockers($project));
    }

    public function test_a_pure_supply_operation_only_needs_a_delivery(): void
    {
        $project = Project::factory()->active()->create();

        // No manufacturing orders: nothing to finish or inspect, but it must
        // have been delivered.
        $this->assertSame(
            [__('errors.operations.blockers.no_active_delivery')],
            $this->service->completionBlockers($project),
        );

        $this->voucher($project, $this->finishedGood(), 3);

        $this->assertSame([], $this->service->completionBlockers($project));
        $this->service->markCompleted($project);
        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_the_status_check_still_runs_first(): void
    {
        $draft = Project::factory()->draft()->create();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(__('errors.operations.illegal_transition', [
            'current' => 'draft',
            'allowed' => 'in_progress, on_hold',
        ]));

        $this->service->markCompleted($draft);
    }

    // ----------------------------------------------------------- Filament

    public function test_the_complete_button_is_disabled_with_the_blockers_in_its_tooltip(): void
    {
        $project = Project::factory()->active()->create();
        $order = WorkOrder::factory()->create([
            'project_id' => $project->id,
            'status' => WorkOrderStatus::InProgress,
            'manufacturing_finished_at' => null,
        ]);

        Livewire::actingAs($this->userWithRole('General_Manager'))
            ->test(ListActiveProjects::class)
            ->assertTableActionVisible('complete', $project)
            ->assertTableActionDisabled('complete', $project)
            ->callTableAction('complete', $project);

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);

        $blockers = $this->service->completionBlockers($project);
        $this->assertStringContainsString($order->wo_number, implode(' ', $blockers));
    }

    public function test_the_complete_button_works_once_everything_is_done(): void
    {
        [$project] = $this->readyOperation();

        Livewire::actingAs($this->userWithRole('General_Manager'))
            ->test(ListActiveProjects::class)
            ->assertTableActionVisible('complete', $project)
            ->assertTableActionEnabled('complete', $project)
            ->callTableAction('complete', $project);

        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_sales_never_sees_the_complete_button_even_when_the_operation_is_ready(): void
    {
        [$project] = $this->readyOperation();

        foreach (['Sales', 'Sales_Manager', 'Sales_Officer', 'Sales_Head'] as $role) {
            Livewire::actingAs($this->userWithRole($role))
                ->test(ListActiveProjects::class)
                ->assertCanSeeTableRecords([$project])
                ->assertTableActionHidden('complete', $project);
        }

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);
    }

    public function test_the_blocker_texts_exist_in_both_locales(): void
    {
        $keys = [
            'errors.operations.completion_blocked',
            'errors.operations.blockers.manufacturing_not_finished',
            'errors.operations.blockers.quality_sheet_not_passed',
            'errors.operations.blockers.no_active_delivery',
            'errors.operations.blockers.undelivered',
            'resources.operations.completion_blocked_tooltip',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    // ---------------------------------------------------------------- RBAC

    public function test_no_sales_role_holds_the_complete_permission_by_default(): void
    {
        foreach (['Sales', 'Sales_Manager', 'Sales_Officer', 'Sales_Head'] as $role) {
            $this->assertFalse(
                Role::findByName($role, 'web')->hasPermissionTo('operations.complete'),
                "$role must not hold operations.complete",
            );
            $this->assertFalse($this->userWithRole($role)->can('operations.complete'), $role);
        }
    }

    public function test_the_floor_and_general_management_keep_the_complete_permission(): void
    {
        foreach (['Factory_Manager', 'General_Manager', 'Admin'] as $role) {
            $this->assertTrue($this->userWithRole($role)->can('operations.complete'), $role);
        }
    }

    public function test_the_migration_revokes_the_permission_from_sales_roles_and_their_users_only(): void
    {
        $permission = Permission::findByName('operations.complete', 'web');

        // Reproduce production: roles seeded earlier were handed the grant.
        foreach (['Sales', 'Sales_Manager', 'Sales_Officer', 'Sales_Head', 'Factory_Manager'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo($permission);
        }

        $salesUser = $this->userWithRole('Sales_Head');
        $salesUser->givePermissionTo($permission);
        $loneUser = User::factory()->create();
        $loneUser->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($this->userWithRole('Sales')->can('operations.complete'));

        $migration = require database_path('migrations/2026_11_19_000001_revoke_operations_complete_from_sales_roles.php');
        $migration->up();
        $migration->up(); // idempotent

        foreach (['Sales', 'Sales_Manager', 'Sales_Officer', 'Sales_Head'] as $role) {
            $this->assertFalse(Role::findByName($role, 'web')->hasPermissionTo('operations.complete'), $role);
        }

        $this->assertFalse($salesUser->fresh()->can('operations.complete'));
        $this->assertFalse($this->userWithRole('Sales')->can('operations.complete'));

        // Untouched: the factory manager role and a non-sales user's own grant.
        $this->assertTrue(Role::findByName('Factory_Manager', 'web')->hasPermissionTo('operations.complete'));
        $this->assertTrue($loneUser->fresh()->can('operations.complete'));
    }
}
