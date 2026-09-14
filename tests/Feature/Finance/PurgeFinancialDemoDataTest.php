<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\JournalEntry;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * finance:purge-demo-data runs on every production deploy, so it must remove
 * the DEMO-FS data and nothing an accountant entered.
 */
class PurgeFinancialDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_demo_data_and_keeps_real_records(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $demo = $this->entry('DEMO-FS-0001');
        $real = $this->entry('INV-778');

        // 1010 still holds the demo figure; 1110 was corrected by the accountant.
        Account::where('code', '1010')->first()->forceFill(['opening_balance' => 150_000, 'opening_balance_date' => '2026-01-01'])->save();
        Account::where('code', '1110')->first()->forceFill(['opening_balance' => 612_500, 'opening_balance_date' => '2026-01-01'])->save();

        $unused = Customer::create(['name' => 'مصنع الدلتا للأسمنت', 'phone' => '01000000000']);
        $used = Customer::create(['name' => 'المجموعة المصرية للطاقة', 'phone' => '01000000000']);

        $this->partyEntry($unused, 'DEMO-FS — رصيد تجريبي');
        $this->partyEntry($used, 'DEMO-FS — رصيد تجريبي');
        $this->partyEntry($used, 'تحصيل فعلي');

        $this->artisan('finance:purge-demo-data')->assertSuccessful();

        $this->assertModelMissing($demo);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $demo->id]);
        $this->assertModelExists($real);

        $this->assertSame(0.0, (float) Account::where('code', '1010')->value('opening_balance'));
        $this->assertSame(612_500.0, (float) Account::where('code', '1110')->value('opening_balance'));

        $this->assertSame(0, AccountEntry::where('notes', 'like', 'DEMO-FS%')->count());
        $this->assertNull(Customer::withTrashed()->find($unused->id));
        $this->assertNotNull(Customer::find($used->id));

        // A second run is a harmless no-op.
        $this->artisan('finance:purge-demo-data')->assertSuccessful();
        $this->assertModelExists($real);
    }

    private function entry(string $documentNumber): JournalEntry
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JV-' . $documentNumber,
            'document_number' => $documentNumber,
            'document_type' => DocumentType::Settlement,
            'entry_date' => '2026-12-31',
            'description' => 'قيد',
            'status' => JournalStatus::Posted,
            'currency' => 'EGP',
        ]);

        $entry->lines()->create(['account_id' => Account::where('code', '5030')->value('id'), 'direction' => AccountDirection::Debit, 'amount' => 100]);
        $entry->lines()->create(['account_id' => Account::where('code', '1010')->value('id'), 'direction' => AccountDirection::Credit, 'amount' => 100]);

        return $entry;
    }

    private function partyEntry(Customer $customer, string $notes): void
    {
        AccountEntry::create([
            'party_type' => $customer->getMorphClass(),
            'party_id' => $customer->getKey(),
            'entry_date' => '2026-12-31',
            'direction' => AccountDirection::Debit,
            'amount' => 1_000,
            'notes' => $notes,
        ]);
    }
}
