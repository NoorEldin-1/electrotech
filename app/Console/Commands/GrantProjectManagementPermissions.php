<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * قسم إدارة المشروعات (Presentation.pptx) — RoleAndPermissionSeeder only fills
 * roles it creates, so on an existing install the new permissions reach Admin
 * but none of the departments. This grants them to the existing roles, once,
 * without touching anything else the admin has configured. Idempotent.
 */
class GrantProjectManagementPermissions extends Command
{
    protected $signature = 'permissions:grant-project-management';

    protected $description = 'Create the project-management (timeline / customer follow-up) permissions and grant them to the existing department roles';

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        'Sales' => ['operations.timeline', 'customer_interactions.view', 'customer_interactions.manage'],
        'Sales_Manager' => ['operations.timeline', 'customer_interactions.view', 'customer_interactions.manage'],
        'Technical_Office' => ['operations.timeline', 'customer_interactions.view'],
        'Factory_Manager' => ['operations.timeline'],
        'General_Manager' => ['operations.timeline', 'customer_interactions.view'],
    ];

    public function handle(): int
    {
        // Creates the permissions and re-syncs Admin with every permission.
        $this->callSilently('db:seed', ['--class' => RoleAndPermissionSeeder::class, '--force' => true]);

        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                $this->warn("Role {$roleName} not found — skipped.");

                continue;
            }

            $role->givePermissionTo($permissions);
            $this->info("{$roleName}: ".implode(', ', $permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return self::SUCCESS;
    }
}
