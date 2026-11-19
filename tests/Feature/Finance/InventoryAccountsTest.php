<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
use App\Enums\WarehouseType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\BalanceSheetService;
use App\Services\InventoryAccounts;
use App\Services\JournalEntryService;
use App\Services\TrialBalanceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Final_Modifications Task 6 (سلايد 2–3, ruling R1): the single "1300 المخزون"
 * account is kept as the parent and each store gets its own account —
 * 1310 مخزن الخامات, 1320 مخزن تحت التشغيل, 1330 مخزن المنتجات التامة.
 */
class InventoryAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const STORES = [
        '1310' => 'مخزن الخامات',
        '1320' => 'مخزن تحت التشغيل',
        '1330' => 'مخزن المنتجات التامة',
    ];

    // ---------------------------------------------------------------- seeder

    public function test_seeder_adds_the_three_store_accounts_as_current_assets(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        foreach (self::STORES as $code => $name) {
            $account = Account::where('code', $code)->first();

            $this->assertNotNull($account, "Account {$code} must be seeded.");
            $this->assertSame($name, $account->name);
            $this->assertSame(AccountType::Asset, $account->type);
            $this->assertSame(AccountDirection::Debit, $account->nature);
            // Same line as 1300 on the balance sheet, so the stores are part of
            // current assets and of the trial balance like any other account.
            $this->assertSame(StatementSection::CurrentAssets, $account->statement_section);
            $this->assertTrue($account->is_active);
        }
    }

    public function test_1300_stays_as_the_parent_of_the_three_stores(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $parent = Account::where('code', '1300')->firstOrFail();

        $this->assertSame('المخزون', $parent->name);
        $this->assertNull($parent->parent_id);

        foreach (array_keys(self::STORES) as $code) {
            $this->assertSame($parent->id, Account::where('code', $code)->firstOrFail()->parent_id);
        }
    }

    public function test_seeder_is_idempotent_and_never_overwrites_an_admin_edit(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $other = Account::where('code', '1200')->firstOrFail();
        Account::where('code', '1310')->firstOrFail()->forceFill([
            'name' => 'مخزن الخامات الرئيسي',
            'parent_id' => $other->id,
        ])->save();

        $count = Account::count();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame($count, Account::count());
        $this->assertSame(3, Account::whereIn('code', array_keys(self::STORES))->count());

        $edited = Account::where('code', '1310')->firstOrFail();
        $this->assertSame('مخزن الخامات الرئيسي', $edited->name);
        $this->assertSame($other->id, $edited->parent_id, 'A store moved by hand must not be re-parented.');
    }

    public function test_the_config_keys_carry_the_agreed_codes(): void
    {
        $this->assertSame('1310', config('operations.raw_materials_account_code'));
        $this->assertSame('1320', config('operations.wip_account_code'));
        $this->assertSame('1330', config('operations.finished_goods_account_code'));
        // The legacy single account stays configured as the fallback.
        $this->assertSame('1300', config('operations.inventory_account_code'));
        $this->assertSame('2010', config('operations.suppliers_account_code'));
    }

    // -------------------------------------------------------------- resolver

    public function test_resolver_returns_the_seeded_accounts(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $accounts = app(InventoryAccounts::class);

        $this->assertSame('1310', $accounts->rawMaterials()?->code);
        $this->assertSame('1320', $accounts->wip()?->code);
        $this->assertSame('1330', $accounts->finishedGoods()?->code);
        $this->assertSame('1300', $accounts->legacy()?->code);
        $this->assertSame('2010', $accounts->suppliers()?->code);

        $this->assertSame('1310', $accounts->forWarehouse(WarehouseType::RawMaterials)?->code);
        $this->assertSame('1320', $accounts->forWarehouse(WarehouseType::WorkInProgress)?->code);
        $this->assertSame('1330', $accounts->forWarehouse(WarehouseType::FinishedGoods)?->code);
    }

    public function test_resolver_codes_come_from_config(): void
    {
        $this->assertSame(
            ['raw_materials' => '1310', 'wip' => '1320', 'finished_goods' => '1330'],
            app(InventoryAccounts::class)->codes(),
        );

        config([
            'operations.raw_materials_account_code' => '1311',
            'operations.wip_account_code' => '1321',
            'operations.finished_goods_account_code' => '1331',
        ]);

        $this->assertSame(
            ['raw_materials' => '1311', 'wip' => '1321', 'finished_goods' => '1331'],
            app(InventoryAccounts::class)->codes(),
        );
    }

    public function test_resolver_returns_null_for_a_missing_or_inactive_account(): void
    {
        $accounts = app(InventoryAccounts::class);

        // Chart not seeded at all.
        $this->assertNull($accounts->rawMaterials());
        $this->assertNull($accounts->wip());
        $this->assertNull($accounts->finishedGoods());

        $this->seed(ChartOfAccountsSeeder::class);
        $this->assertNotNull($accounts->wip());

        // Nothing is memoised: a chart edited afterwards is seen immediately.
        Account::where('code', '1320')->firstOrFail()->forceFill(['is_active' => false])->save();
        $this->assertNull($accounts->wip(), 'An inactive account is invisible to the statements, so it counts as missing.');

        Account::where('code', '1330')->firstOrFail()->delete();
        $this->assertNull($accounts->finishedGoods());
    }

    // ------------------------------------------------------------ statements

    public function test_the_stores_appear_in_the_trial_balance(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $this->postEntry('1310', '2010', 750.0);

        $rows = app(TrialBalanceService::class)->build(null, 'EGP');
        $byCode = $rows->mapWithKeys(fn (array $row): array => [$row['account']->code => $row]);

        $this->assertTrue($byCode->has('1310'), 'The raw-materials store must be listed once it has activity.');
        $this->assertEqualsWithDelta(750.0, $byCode['1310']['debit'], 0.001);
        $this->assertEqualsWithDelta(750.0, $byCode['2010']['credit'], 0.001);
    }

    public function test_the_balance_sheet_folds_the_stores_into_the_inventory_line(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        Account::where('code', '1310')->firstOrFail()->forceFill(['opening_balance' => 5000])->save();
        Account::where('code', '1320')->firstOrFail()->forceFill(['opening_balance' => 3000])->save();

        $sheet = app(BalanceSheetService::class)->build(Carbon::today());
        $rows = collect($sheet['current_assets']['rows']);

        $inventory = $rows->first(fn (array $row): bool => ($row['account']?->code ?? null) === '1300');

        $this->assertNotNull($inventory, 'The parent keeps ONE inventory line.');
        $this->assertEqualsWithDelta(8000.0, $inventory['amount'], 0.01);
        $this->assertNull(
            $rows->first(fn (array $row): bool => in_array($row['account']?->code ?? null, ['1310', '1320', '1330'], true)),
            'A store never gets a line of its own beside the parent.',
        );
    }

    private function postEntry(string $debitCode, string $creditCode, float $amount): void
    {
        $entry = JournalEntry::factory()->create(['entry_date' => now()->toDateString()]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => Account::where('code', $debitCode)->firstOrFail()->id,
            'direction' => AccountDirection::Debit,
            'amount' => $amount,
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => Account::where('code', $creditCode)->firstOrFail()->id,
            'direction' => AccountDirection::Credit,
            'amount' => $amount,
        ]);

        app(JournalEntryService::class)->post($entry->fresh('lines'));
    }
}
