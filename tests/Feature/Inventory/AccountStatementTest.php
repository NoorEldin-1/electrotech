<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AccountDirection;
use App\Models\AccountEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountStatementService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_statement_orders_entries_and_computes_running_balance(): void
    {
        $supplier = Supplier::factory()->create();

        foreach ([
            ['2026-01-01', AccountDirection::Credit, 1000],
            ['2026-01-05', AccountDirection::Debit, -400],
            ['2026-01-10', AccountDirection::Credit, 250],
        ] as [$date, $dir, $amount]) {
            AccountEntry::create([
                'party_type' => $supplier->getMorphClass(),
                'party_id' => $supplier->id,
                'entry_date' => $date,
                'direction' => $dir,
                'amount' => $amount,
            ]);
        }

        $statement = app(AccountStatementService::class)->for($supplier);

        $this->assertCount(3, $statement);
        $this->assertEquals([1000.0, 600.0, 850.0], $statement->pluck('running_balance')->all());
        $this->assertEquals(850, app(AccountStatementService::class)->balanceFor($supplier));
    }

    public function test_statement_is_scoped_to_one_party(): void
    {
        $a = Supplier::factory()->create();
        $b = Supplier::factory()->create();

        AccountEntry::create(['party_type' => $a->getMorphClass(), 'party_id' => $a->id, 'entry_date' => '2026-01-01', 'direction' => AccountDirection::Credit, 'amount' => 500]);
        AccountEntry::create(['party_type' => $b->getMorphClass(), 'party_id' => $b->id, 'entry_date' => '2026-01-01', 'direction' => AccountDirection::Credit, 'amount' => 999]);

        $statement = app(AccountStatementService::class)->for($a);

        $this->assertCount(1, $statement);
        $this->assertEquals(500.0, $statement->first()->running_balance);
    }

    public function test_rbac_finance_sees_both_statements(): void
    {
        $finance = User::factory()->create();
        $finance->assignRole('Finance');

        $this->assertTrue($finance->can('supplier_statements.view'));
        $this->assertTrue($finance->can('customer_statements.view'));

        $warehouse = User::factory()->create();
        $warehouse->assignRole('Warehouse_Manager');
        $this->assertFalse($warehouse->can('supplier_statements.view'));
    }
}
