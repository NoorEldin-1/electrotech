<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The staff sheet (2026-10-20): one account per person, each holding only the
 * job-function roles of their own screens.
 *
 * Manual, run once per environment — never from deploy.sh. Safe to re-run: an
 * account that already exists is left exactly as it is (password, name and
 * the roles an admin may have changed since), so a re-run only adds who is
 * missing. --sync-roles resets the existing accounts' roles to the sheet.
 */
class ProvisionStaffUsers extends Command
{
    protected $signature = 'staff:provision {--sync-roles : Reset the roles of accounts that already exist to the sheet}';

    protected $description = 'Create the staff accounts from the staff sheet with their job-function roles';

    /** @var array<string, array{name: string, roles: array<int, string>}> */
    public const STAFF = [
        'asmaa.mohamed@electrotech.com' => ['name' => 'أسماء محمد', 'roles' => ['Timeline_Coordinator']],
        'yassin.mohamed@electrotech.com' => ['name' => 'ياسين محمد', 'roles' => ['Purchasing_Officer', 'Active_Operations_Viewer']],
        'raghda@electrotech.com' => ['name' => 'رغدة', 'roles' => ['Sales_Officer']],
        'mariam.ibrahim@electrotech.com' => ['name' => 'مريم إبراهيم', 'roles' => ['Sales_Officer']],
        'afaf.fawzy@electrotech.com' => ['name' => 'م. عفاف فوزي', 'roles' => ['Sales_Head', 'Timeline_Viewer']],
        'sameh.magdy@electrotech.com' => ['name' => 'سامح مجدي', 'roles' => ['Warehouse_Keeper', 'Ledger_Viewer']],
        'mohamed.dabour@electrotech.com' => ['name' => 'محمد دبور', 'roles' => ['Warehouse_Keeper', 'Items_Editor', 'Purchase_Orders_Viewer']],
        'mohamed.abdelrahim@electrotech.com' => ['name' => 'محمد عبد الرحيم', 'roles' => ['Quality_Inspector']],
        'ziad@electrotech.com' => ['name' => 'م. زياد', 'roles' => ['Production_Viewer', 'Active_Operations_Viewer']],
    ];

    public function handle(): int
    {
        // Creates the job-function roles if this install does not have them yet.
        $this->callSilently('db:seed', ['--class' => RoleAndPermissionSeeder::class, '--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $missingRoles = collect(self::STAFF)->pluck('roles')->flatten()->unique()
            ->diff(Role::query()->where('guard_name', 'web')->pluck('name'));

        if ($missingRoles->isNotEmpty()) {
            $this->error('Missing roles: '.$missingRoles->implode(', '));

            return self::FAILURE;
        }

        $rows = [];

        foreach (self::STAFF as $email => $staff) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $password = Str::password(12, symbols: false);
                $user = User::query()->create([
                    'name' => $staff['name'],
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->syncRoles($staff['roles']);
                $rows[] = [$staff['name'], $email, $password, implode(' + ', $staff['roles']), 'created'];

                continue;
            }

            if ($this->option('sync-roles')) {
                $user->syncRoles($staff['roles']);
                $rows[] = [$staff['name'], $email, '(unchanged)', implode(' + ', $staff['roles']), 'roles reset'];

                continue;
            }

            $rows[] = [$staff['name'], $email, '(unchanged)', $user->getRoleNames()->implode(' + '), 'already exists — untouched'];
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->table(['Name', 'Email', 'Password', 'Roles', 'Result'], $rows);
        $this->warn('Passwords are shown once only — hand them over now.');

        return self::SUCCESS;
    }
}
