<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_finance_role_has_general_ledger_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Finance');

        foreach ([
            'accounts.view',
            'accounts.create',
            'accounts.edit',
            'journal_entries.view',
            'journal_entries.create',
            'journal_entries.edit',
            'journal_entries.post',
            'general_ledger.view',
            'trial_balance.view',
        ] as $perm) {
            $this->assertTrue($user->can($perm), "Finance missing $perm");
        }
    }

    public function test_admin_has_all_general_ledger_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        foreach ([
            'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete',
            'journal_entries.view', 'journal_entries.create', 'journal_entries.edit', 'journal_entries.post', 'journal_entries.delete',
            'general_ledger.view', 'trial_balance.view',
        ] as $perm) {
            $this->assertTrue($user->can($perm), "Admin missing $perm");
        }
    }

    public function test_warehouse_role_has_no_general_ledger_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Warehouse_Manager');

        $this->assertFalse($user->can('journal_entries.create'));
        $this->assertFalse($user->can('accounts.view'));
        $this->assertFalse($user->can('trial_balance.view'));
    }

    public function test_sales_role_has_no_general_ledger_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        $this->assertFalse($user->can('journal_entries.post'));
        $this->assertFalse($user->can('general_ledger.view'));
    }

    public function test_posted_entry_cannot_be_edited_via_policy(): void
    {
        $finance = User::factory()->create();
        $finance->assignRole('Finance');

        $draft = JournalEntry::factory()->create(['status' => JournalStatus::Draft]);
        $posted = JournalEntry::factory()->posted()->create();

        $this->assertTrue($finance->can('update', $draft));
        $this->assertFalse($finance->can('update', $posted));
        $this->assertFalse($finance->can('post', $posted));
    }
}
