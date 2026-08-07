<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Identity;

use Spatie\Permission\Models\Role;
use Tests\Feature\Api\V1\ApiTestCase;

class RoleAndPermissionTest extends ApiTestCase
{
    public function test_roles_index_lists_seeded_roles_with_user_counts(): void
    {
        $caller = $this->userWith(['roles.manage']);

        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/roles');

        $response->assertOk();
        $this->assertPaginatedEnvelope($response);

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Admin', $names);
        $this->assertContains('Sales', $names);

        $this->assertArrayHasKey('users_count', $response->json('data.0'));
    }

    public function test_role_show_includes_its_permissions(): void
    {
        $caller = $this->userWith(['roles.manage']);
        $sales = Role::where('name', 'Sales')->firstOrFail();

        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/roles/'.$sales->id);

        $response->assertOk();
        $this->assertItemEnvelope($response);
        $this->assertContains('projects.view', $response->json('data.permissions'));
    }

    public function test_a_role_can_be_created_with_permissions(): void
    {
        $caller = $this->userWith(['roles.manage']);

        $response = $this->actingAsApi($caller)->apiPost(self::BASE.'/roles', [
            'name' => 'Warehouse_Lead',
            'permissions' => ['inventory.view', 'issue_vouchers.create'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Warehouse_Lead');

        $role = Role::where('name', 'Warehouse_Lead')->firstOrFail();
        $this->assertTrue($role->hasPermissionTo('inventory.view'));
        $this->assertTrue($role->hasPermissionTo('issue_vouchers.create'));
    }

    public function test_a_role_cannot_be_created_with_a_permission_outside_the_catalog(): void
    {
        $caller = $this->userWith(['roles.manage']);

        // Inventing a permission would create one nothing ever checks — a
        // silent no-op that looks like a granted right on the roles screen.
        $this->actingAsApi($caller)->apiPost(self::BASE.'/roles', [
            'name' => 'Fantasy',
            'permissions' => ['projects.teleport'],
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['permissions.0']]]);
    }

    public function test_a_duplicate_role_name_is_rejected(): void
    {
        $caller = $this->userWith(['roles.manage']);

        $this->actingAsApi($caller)->apiPost(self::BASE.'/roles', [
            'name' => 'Sales',
            'permissions' => ['projects.view'],
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name']]]);
    }

    public function test_updating_permissions_replaces_the_whole_set(): void
    {
        $caller = $this->userWith(['roles.manage']);
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
        $role->syncPermissions(['projects.view', 'projects.create']);

        $response = $this->actingAsApi($caller)->apiPatch(self::BASE.'/roles/'.$role->id, [
            'permissions' => ['projects.view'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.permissions', ['projects.view']);

        // Replace, not merge — mirrors the panel's checkbox grid.
        $this->assertFalse($role->fresh()->hasPermissionTo('projects.create'));
    }

    public function test_a_permission_change_takes_effect_immediately_for_an_existing_session(): void
    {
        $caller = $this->userWith(['roles.manage']);
        $subject = $this->userWith(['users.view']);

        // The subject can list users right now.
        $this->actingAsApi($subject)->apiGet(self::BASE.'/users')->assertOk();

        $role = $subject->roles->first();
        $this->actingAsApi($caller)->apiPatch(self::BASE.'/roles/'.$role->id, [
            'permissions' => ['projects.view'],
        ])->assertOk();

        // Spatie caches the whole permission graph. Without an explicit cache
        // flush on write, the revoked permission would keep working until the
        // cache expired — the exact bug this asserts against.
        $this->actingAsApi($subject->fresh())->apiGet(self::BASE.'/users')
            ->assertStatus(403);
    }

    public function test_the_admin_role_cannot_be_deleted(): void
    {
        $caller = $this->userWith(['roles.manage']);
        $admin = Role::where('name', 'Admin')->firstOrFail();

        $response = $this->actingAsApi($caller)->apiDelete(self::BASE.'/roles/'.$admin->id);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'forbidden');
        $this->assertDatabaseHas('roles', ['id' => $admin->id]);
    }

    public function test_a_role_can_be_deleted(): void
    {
        $caller = $this->userWith(['roles.manage']);
        $role = Role::create(['name' => 'Disposable', 'guard_name' => 'web']);

        $this->actingAsApi($caller)->apiDelete(self::BASE.'/roles/'.$role->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_permissions_index_returns_the_catalog_grouped_and_labelled(): void
    {
        $caller = $this->userWith(['roles.manage']);

        $response = $this->actingAsApi($caller)->apiGet(self::BASE.'/permissions');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['group', 'group_label', 'permissions' => [['name', 'label']]]],
        ]);

        $groups = array_column($response->json('data'), 'group');
        $this->assertContains('projects', $groups);

        // Labels must be resolved, not raw translation keys leaking through.
        foreach ($response->json('data') as $group) {
            $this->assertStringNotContainsString('resources.roles.', $group['group_label']);

            foreach ($group['permissions'] as $permission) {
                $this->assertStringNotContainsString('resources.roles.', $permission['label']);
            }
        }
    }

    public function test_permission_labels_honour_the_accept_language_header(): void
    {
        $caller = $this->userWith(['roles.manage']);

        $english = $this->actingAsApi($caller)
            ->apiGet(self::BASE.'/permissions', ['Accept-Language' => 'en']);
        $arabic = $this->actingAsApi($caller)
            ->apiGet(self::BASE.'/permissions', ['Accept-Language' => 'ar']);

        $english->assertHeader('Content-Language', 'en');
        $arabic->assertHeader('Content-Language', 'ar');

        $this->assertNotSame(
            $english->json('data.0.group_label'),
            $arabic->json('data.0.group_label'),
            'Arabic and English labels should differ; Accept-Language is being ignored.',
        );
    }

    public function test_rbac_denies_role_endpoints_without_roles_manage(): void
    {
        $powerless = $this->userWithoutPermissions();
        $role = Role::where('name', 'Sales')->firstOrFail();

        $cases = [
            ['GET', self::BASE.'/roles'],
            ['GET', self::BASE.'/roles/'.$role->id],
            ['POST', self::BASE.'/roles'],
            ['PATCH', self::BASE.'/roles/'.$role->id],
            ['DELETE', self::BASE.'/roles/'.$role->id],
            ['GET', self::BASE.'/permissions'],
        ];

        foreach ($cases as [$method, $uri]) {
            $response = $this->actingAsApi($powerless)->apiJson($method, $uri, []);

            $this->assertSame(403, $response->status(), "{$method} {$uri} should be 403.");
            $this->assertErrorEnvelope($response, 'forbidden');
        }
    }
}
