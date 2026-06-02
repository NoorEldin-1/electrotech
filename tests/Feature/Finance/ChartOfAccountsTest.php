<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Models\Account;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_accounts_with_derived_nature(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertGreaterThan(30, Account::count());

        $treasury = Account::where('code', '1010')->first();
        $this->assertNotNull($treasury);
        $this->assertSame('الخزينة', $treasury->name);
        $this->assertSame(AccountType::Asset, $treasury->type);
        $this->assertSame(AccountDirection::Debit, $treasury->nature);

        $sales = Account::where('code', '4010')->first();
        $this->assertSame(AccountType::Revenue, $sales->type);
        $this->assertSame(AccountDirection::Credit, $sales->nature);

        $usdBank = Account::where('code', '1111')->first();
        $this->assertSame('USD', $usdBank->currency);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $count = Account::count();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame($count, Account::count());
    }

    public function test_account_type_natural_direction_mapping(): void
    {
        $this->assertSame(AccountDirection::Debit, AccountType::Asset->naturalDirection());
        $this->assertSame(AccountDirection::Debit, AccountType::Expense->naturalDirection());
        $this->assertSame(AccountDirection::Credit, AccountType::Liability->naturalDirection());
        $this->assertSame(AccountDirection::Credit, AccountType::Equity->naturalDirection());
        $this->assertSame(AccountDirection::Credit, AccountType::Revenue->naturalDirection());
    }

    public function test_account_code_is_unique(): void
    {
        Account::factory()->create(['code' => '9999']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Account::factory()->create(['code' => '9999']);
    }

    public function test_natural_sign_follows_nature(): void
    {
        $asset = Account::factory()->ofType(AccountType::Asset)->create();
        $liability = Account::factory()->ofType(AccountType::Liability)->create();

        $this->assertSame(1, $asset->naturalSign());
        $this->assertSame(-1, $liability->naturalSign());
    }
}
