<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Reports;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\JournalEntryService;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * GET /reports/inventory-valuation: the same figures as the panel's page, under
 * the same permission, in the API's decimal-string contract.
 */
class InventoryValuationApiTest extends ApiTestCase
{
    private Account $raw;

    private Account $offset;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'operations.inventory_account_code' => '1300',
            'operations.raw_materials_account_code' => '1310',
            'operations.wip_account_code' => '1320',
            'operations.finished_goods_account_code' => '1330',
        ]);

        $this->raw = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1310', 'name' => 'مخزن الخامات']);
        Account::factory()->ofType(AccountType::Asset)->create(['code' => '1320', 'name' => 'مخزن تحت التشغيل']);
        Account::factory()->ofType(AccountType::Asset)->create(['code' => '1330', 'name' => 'مخزن المنتجات التامة']);
        $this->offset = Account::factory()->ofType(AccountType::Liability)->create(['code' => '2010', 'name' => 'الموردون']);
    }

    public function test_it_returns_ledger_balances_as_decimal_strings(): void
    {
        $this->postEntry(12500, '2026-01-10');

        $response = $this->actingAsApi($this->userWith(['reports.inventory_valuation']))
            ->apiGet(self::BASE.'/reports/inventory-valuation');

        $response->assertOk();
        $this->assertItemEnvelope($response);

        $response->assertJsonPath('data.totals.ledger', '12500.00');
        $response->assertJsonPath('data.totals.stock_card', '0.00');
        $response->assertJsonPath('data.totals.difference', '12500.00');
        $response->assertJsonPath('data.totals.reconciled', false);

        $raw = collect($response->json('data.stores'))->firstWhere('key', 'raw_materials');
        $this->assertSame('12500.00', $raw['ledger']);
        $this->assertSame('1310', $raw['account']['code']);
        $this->assertSame('raw_materials', $raw['warehouse']['value']);
    }

    public function test_as_of_filters_the_ledger(): void
    {
        $this->postEntry(1000, '2026-01-10');
        $this->postEntry(500, '2026-03-01');

        $this->actingAsApi($this->userWith(['reports.inventory_valuation']))
            ->apiGet(self::BASE.'/reports/inventory-valuation?as_of=2026-02-01')
            ->assertOk()
            ->assertJsonPath('data.as_of', '2026-02-01')
            ->assertJsonPath('data.totals.ledger', '1000.00');
    }

    public function test_an_unparseable_date_is_a_validation_error(): void
    {
        $this->actingAsApi($this->userWith(['reports.inventory_valuation']))
            ->apiGet(self::BASE.'/reports/inventory-valuation?as_of=not-a-date')
            ->assertStatus(422);
    }

    public function test_it_needs_its_own_permission(): void
    {
        // Another finance report's permission does not open it.
        $this->actingAsApi($this->userWith(['trial_balance.view']))
            ->apiGet(self::BASE.'/reports/inventory-valuation')
            ->assertStatus(403);
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->apiGet(self::BASE.'/reports/inventory-valuation')->assertStatus(401);
    }

    private function postEntry(float $amount, string $date): void
    {
        $entry = JournalEntry::factory()->create(['entry_date' => $date]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->raw->id,
            'direction' => AccountDirection::Debit,
            'amount' => $amount,
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->offset->id,
            'direction' => AccountDirection::Credit,
            'amount' => $amount,
        ]);

        app(JournalEntryService::class)->post($entry->fresh('lines'));
    }
}
