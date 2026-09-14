<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\DeliveryVoucher;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\AdditionVoucher;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Removes every trace of the old financial-statements demo seeder (DEMO-FS)
 * from a live ledger: its journal entries, its party sub-ledger postings, the
 * opening balances it wrote, and the placeholder customers/suppliers it made.
 *
 * Conservative on purpose — it runs on production from deploy.sh:
 *   • opening balances are cleared only while they still hold the exact demo
 *     figure dated 2026-01-01, so an accountant's real figure is never touched;
 *   • a demo customer/supplier is removed only if nothing else references it.
 *
 * Idempotent: once the data is gone, every run is a no-op.
 */
class PurgeFinancialDemoData extends Command
{
    private const MARKER = 'DEMO-FS';

    private const OPENING_DATE = '2026-01-01';

    /** Account code ⇒ the opening balance the demo seeder wrote. */
    private const DEMO_OPENINGS = [
        '1010' => 150_000, '1110' => 600_000, '1140' => 250_000, '1300' => 900_000,
        '1200' => 480_000, '1230' => 120_000, '1250' => 60_000, '1021' => 80_000,
        '1410' => 1_000_000, '1420' => 2_000_000, '1430' => 1_500_000, '1440' => 300_000,
        '1450' => 400_000, '1460' => 500_000, '1470' => 120_000,
        '2010' => 350_000, '2030' => 200_000, '2040' => 90_000, '2050' => 30_000,
        '2070' => 400_000, '2100' => 70_000, '2110' => 50_000,
        '3020' => 4_730_000, '3030' => 300_000, '3040' => 200_000,
        '1022' => 250_000, '2061' => 250_000,
    ];

    private const DEMO_CUSTOMERS = [
        'شركة النيل للمقاولات الكهربائية',
        'المجموعة المصرية للطاقة',
        'الشركة العربية للتوريدات الصناعية',
        'مصنع الدلتا للأسمنت',
        'هيئة كهرباء القاهرة الكبرى',
        'شركة السويس للبتروكيماويات',
    ];

    private const DEMO_SUPPLIERS = [
        'الشركة المتحدة للكابلات',
        'النصر للصناعات الكهربائية',
        'مصر للمواسير المعدنية',
        'التقنية الحديثة للأدوات',
        'الأمانة لقطع الغيار',
    ];

    protected $signature = 'finance:purge-demo-data';

    protected $description = 'Delete the financial-statements demo data (DEMO-FS) from the ledger.';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $entryIds = JournalEntry::query()
                ->where('document_number', 'like', self::MARKER . '%')
                ->pluck('id');

            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->delete();
            $entries = JournalEntry::query()->whereIn('id', $entryIds)->delete();

            $partyEntries = AccountEntry::query()->where('notes', 'like', self::MARKER . '%')->delete();

            $openings = 0;
            foreach (self::DEMO_OPENINGS as $code => $amount) {
                $account = Account::withTrashed()->where('code', (string) $code)->first();

                if ($account !== null
                    && (float) $account->opening_balance === (float) $amount
                    && $account->opening_balance_date?->toDateString() === self::OPENING_DATE) {
                    $account->forceFill(['opening_balance' => 0, 'opening_balance_date' => null])->save();
                    $openings++;
                }
            }

            $this->info("Journal entries deleted: {$entries} · party postings deleted: {$partyEntries} · opening balances cleared: {$openings}");
        });

        $customers = $this->purgeParties(Customer::class, self::DEMO_CUSTOMERS, '01000000000', [Project::class, DeliveryVoucher::class]);
        $suppliers = $this->purgeParties(Supplier::class, self::DEMO_SUPPLIERS, '01100000000', [PurchaseOrder::class, AdditionVoucher::class]);

        $this->info("Demo customers deleted: {$customers} · demo suppliers deleted: {$suppliers}");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Customer|Supplier>  $model
     * @param  list<string>  $names
     * @param  list<class-string>  $dependents  models holding a `customer_id` / `supplier_id`
     */
    private function purgeParties(string $model, array $names, string $placeholderPhone, array $dependents): int
    {
        $foreignKey = $model === Customer::class ? 'customer_id' : 'supplier_id';
        $deleted = 0;

        $parties = $model::withTrashed()
            ->whereIn('name', $names)
            ->where('phone', $placeholderPhone)
            ->get();

        foreach ($parties as $party) {
            $inUse = AccountEntry::query()
                ->where('party_type', $party->getMorphClass())
                ->where('party_id', $party->getKey())
                ->exists();

            foreach ($dependents as $dependent) {
                $inUse = $inUse || $dependent::query()->where($foreignKey, $party->getKey())->exists();
            }

            if ($inUse) {
                $this->warn("Kept {$party->name}: it is used by real records.");

                continue;
            }

            try {
                $party->forceDelete();
                $deleted++;
            } catch (QueryException) {
                $this->warn("Kept {$party->name}: another table still references it.");
            }
        }

        return $deleted;
    }
}
