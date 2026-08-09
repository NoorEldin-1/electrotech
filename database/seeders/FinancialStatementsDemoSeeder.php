<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountDirection;
use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * بيانات تجريبية للقوائم المالية — a full financial year (2026) built so that
 * EVERY line of all four statements (ماليات.pptx) carries a number and can be
 * checked on screen.
 *
 * What it covers, deliberately:
 *   • رصيد أول المدة on every balance-sheet account, so the cash-flow statement
 *     has a real "رصيد اول الفترة" to subtract from — a statement with no
 *     opening side proves nothing.
 *   • Every income-statement row: sales AND returns, all five cost-of-sales
 *     components, other revenue, capital gains, currency differences in BOTH
 *     directions through the same account, G&A, depreciation, closed letters of
 *     credit, and interest.
 *   • Every cash-flow section: non-cash adjustments, all four working-capital
 *     cases from سلايد 9, an investing purchase, and financing in both
 *     directions (capital in, partner drawings out).
 *   • سلايد 7 in both states: customers whose sub-ledger reconciles EXACTLY to
 *     the control account, and suppliers left with a deliberate 40,000
 *     difference, so the reconciliation row can be seen doing its job.
 *   • A draft (unposted) entry and an entry dated in the NEXT year, so the
 *     "posted only" and "period only" rules are visible rather than assumed.
 *
 * Idempotent: it deletes the demo entries it created (identified by their
 * document_number prefix) before re-creating them, so running it twice does not
 * double every figure.
 */
class FinancialStatementsDemoSeeder extends Seeder
{
    /** Marks every journal entry this seeder owns, so a re-run can clear them. */
    private const MARKER = 'DEMO-FS';

    private const YEAR = 2026;

    /** @var array<string, Account> code ⇒ account */
    private array $accounts = [];

    public function run(): void
    {
        // On a server this runs unattended from deploy.sh, so it has to decide
        // for itself whether re-seeding is safe. It is NOT safe once the data
        // is already there: setOpeningBalances() writes onto balance-sheet
        // accounts, and re-running it after the accountant has entered real
        // opening figures would overwrite them. The marker below is the
        // evidence that a previous run already happened.
        //
        // SEED_DEMO_FINANCIALS=true forces a refresh, which is what you want
        // locally and after changing the demo figures.
        if ($this->alreadySeeded() && ! env('SEED_DEMO_FINANCIALS')) {
            $this->command?->warn(
                'Financial statements demo data is already present — skipping. '
                . 'Set SEED_DEMO_FINANCIALS=true to re-seed.'
            );

            return;
        }

        $this->call(ChartOfAccountsSeeder::class);

        $this->accounts = Account::query()->get()->keyBy('code')->all();

        $this->reset();
        $this->setOpeningBalances();
        $this->postYearActivity();
        $this->seedPartySubLedgers();

        $this->command?->info('Financial statements demo data seeded for ' . self::YEAR . '.');
    }

    /** Did a previous run already write this demo data? */
    private function alreadySeeded(): bool
    {
        return JournalEntry::query()
            ->where('document_number', 'like', self::MARKER . '%')
            ->exists();
    }

    /**
     * Remove anything a previous run created. Journal lines cascade with their
     * entry; party entries are matched on the same marker in `notes`.
     */
    private function reset(): void
    {
        $entryIds = JournalEntry::query()
            ->where('document_number', 'like', self::MARKER . '%')
            ->pluck('id');

        if ($entryIds->isNotEmpty()) {
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->delete();
            JournalEntry::whereIn('id', $entryIds)->delete();
        }

        AccountEntry::query()->where('notes', 'like', self::MARKER . '%')->delete();
    }

    /**
     * رصيد أول المدة at 2026-01-01. The set below balances on its own —
     * capital is the plug — so the balance sheet is square before a single
     * entry of the year is posted. If it did not balance, every later "does it
     * balance?" check would be meaningless.
     */
    private function setOpeningBalances(): void
    {
        $openings = [
            // ----- الأصول المتداولة والنقدية -----
            '1010' => 150_000,   // الخزينة
            '1110' => 600_000,   // بنك التجاري جنيه
            '1140' => 250_000,   // بنك أبو ظبي الأول جنيه
            '1300' => 900_000,   // المخزون
            '1200' => 480_000,   // العملاء (حساب مراقبة)
            '1230' => 120_000,   // أوراق قبض
            '1250' => 60_000,    // أرصدة مدينة أخرى
            '1021' => 80_000,    // شيكات تحت التحصيل

            // ----- الأصول الثابتة بالتكلفة -----
            '1410' => 1_000_000, // الأراضي
            '1420' => 2_000_000, // المباني
            '1430' => 1_500_000, // معدات وآلات
            '1440' => 300_000,   // أجهزة وأثاث

            // ----- مجمع الإهلاك (طبيعة دائنة) -----
            '1450' => 400_000,
            '1460' => 500_000,
            '1470' => 120_000,

            // ----- الالتزامات المتداولة -----
            '2010' => 350_000,   // مورد محلي (حساب مراقبة)
            '2030' => 200_000,   // أوراق دفع
            '2040' => 90_000,    // مصلحة الضرائب
            '2050' => 30_000,    // صندوق الجزاءات
            '2070' => 400_000,   // تسهيلات أبو ظبي
            '2100' => 70_000,    // أرصدة دائنة أخرى
            '2110' => 50_000,    // مخصص العمولات

            // ----- حقوق الملكية -----
            // 4,730,000 is the plug that squares the opening sheet:
            //   الأصول المتداولة 2,640,000 − الالتزامات 1,190,000 = 1,450,000
            //   + صافي الأصول الثابتة 3,780,000 = 5,230,000 إجمالي الاستثمار
            //   − الاحتياطيات 300,000 − جاري شركاء 200,000 = 4,730,000
            '3020' => 4_730_000, // رأس المال
            '3030' => 300_000,   // الاحتياطيات والمخصصات
            '3040' => 200_000,   // جاري شركاء

            // ----- خارج المعادلات (حاشية سلايد 6) -----
            '1022' => 250_000,   // شيكات ضمان
            '2061' => 250_000,   // غطاء شيكات ضمان
        ];

        // PHP normalises numeric-string array keys to int, so the account code
        // has to be cast back before it is looked up.
        foreach ($openings as $code => $amount) {
            $this->account((string) $code)?->forceFill([
                'opening_balance' => $amount,
                'opening_balance_date' => self::YEAR . '-01-01',
            ])->save();
        }
    }

    /**
     * The year's postings. Each entry is a plain two-sided journal so the
     * demo stays readable in the daybook as well as in the statements.
     */
    private function postYearActivity(): void
    {
        $y = self::YEAR;

        foreach ([
            // ═══ قائمة الدخل — الإيرادات ═══
            ['02-15', 'مبيعات لوحات توزيع — الربع الأول', '1200', '4010', 2_100_000],
            ['05-20', 'مبيعات لوحات توزيع — الربع الثاني', '1200', '4010', 2_400_000],
            ['09-18', 'مبيعات لوحات توزيع — الربع الثالث', '1200', '4010', 1_300_000],
            ['11-25', 'مبيعات تصدير', '1200', '4010', 700_000],
            // مردودات — تُطرح للوصول إلى صافي المبيعات
            ['06-10', 'مردودات مبيعات — لوحة مرتجعة', '4060', '1200', 250_000],

            ['03-30', 'إيرادات متنوعة — بيع خردة', '1010', '4020', 120_000],
            ['08-31', 'فوائد دائنة على الودائع', '1110', '4030', 35_000],
            // بيع أصل قديم في جزأين حتى يكون واقعياً: الأصل يخرج من الدفاتر
            // بقيمته الدفترية (يظهر في أنشطة الاستثمار)، والزيادة عليها ربح
            // رأسمالي في قائمة الدخل.
            ['10-14', 'بيع أجهزة قديمة — استرداد القيمة الدفترية', '1110', '1440', 120_000],
            ['10-14', 'أرباح رأسمالية على بيع الأجهزة القديمة', '1110', '4050', 80_000],
            // فروق العملة في الاتجاهين على نفس الحساب: صافيها ربح 65,000
            ['04-22', 'فروق عملة دائنة', '1110', '4040', 90_000],
            ['07-19', 'فروق عملة مدينة', '4040', '1110', 25_000],

            // ═══ قائمة التشغيل — مكوّنات تكلفة المبيعات ═══
            ['12-20', 'تكلفة البضاعة المباعة عن العام', '5070', '1300', 3_600_000],
            ['12-20', 'مصروفات تشغيل المصنع', '5010', '1010', 420_000],
            ['12-20', 'مصروفات تركيب بالمواقع', '5020', '1010', 180_000],
            ['12-20', 'مصروفات تصدير وشحن', '5050', '1110', 95_000],
            ['12-31', 'إهلاك المعدات والآلات (صناعي)', '5100', '1460', 150_000],
            ['12-20', 'هالك تصنيع غير طبيعي', '5060', '1300', 45_000],

            // ═══ قائمة الدخل — ما دون مجمل الربح ═══
            ['12-20', 'مصروفات عمومية وإدارية', '5030', '1010', 780_000],
            ['12-31', 'إهلاك المباني', '5110', '1450', 100_000],
            ['12-31', 'إهلاك الأجهزة والأثاث', '5110', '1470', 60_000],
            ['09-05', 'اعتمادات مستندية سبق إقفالها', '5080', '1110', 40_000],
            ['12-31', 'فوائد مدينة على التسهيلات', '5090', '2070', 130_000],
            ['12-31', 'مصروفات تمويلية ورسوم بنكية', '5040', '1110', 55_000],
            // مخصصات — تسوية غير نقدية في قائمة التدفقات
            ['12-31', 'تكوين مخصص إضافي', '5030', '3030', 90_000],
            ['12-31', 'مخصص عمولات وكلاء', '5030', '2110', 35_000],

            // ═══ حركة رأس المال العامل (الاحتمالات الأربعة — سلايد 9) ═══
            // 1) أصول زادت ⇒ يُطرح: المخزون يزيد بالمشتريات
            ['03-12', 'مشتريات خامات من موردين محليين', '1300', '2010', 3_800_000],
            // 3) التزامات زادت ⇒ يُضاف — نفس القيد أعلاه على جانب الموردين
            ['04-08', 'سداد دفعات للموردين', '2010', '1110', 3_500_000],
            // 2) أصول نقصت ⇒ يُضاف: تحصيل من العملاء
            ['05-30', 'تحصيل من العملاء — دفعة أولى', '1010', '1200', 1_800_000],
            ['08-28', 'تحصيل من العملاء — دفعة ثانية', '1110', '1200', 3_400_000],
            ['06-25', 'شيكات عملاء تحت التحصيل', '1021', '1200', 150_000],
            ['07-15', 'تحصيل شيكات من البنك', '1110', '1021', 100_000],
            ['09-30', 'ضريبة مستقطعة من المنبع على تحصيلات', '1600', '1200', 65_000],
            ['02-28', 'تحصيل أوراق قبض', '1010', '1230', 70_000],
            ['10-05', 'زيادة أرصدة مدينة أخرى', '1250', '1010', 40_000],
            // 4) التزامات نقصت ⇒ يُطرح
            ['03-20', 'سداد أوراق دفع', '2030', '1110', 80_000],
            ['06-30', 'سداد مستحقات مصلحة الضرائب', '2040', '1110', 60_000],
            ['11-10', 'زيادة أرصدة دائنة أخرى', '1110', '2100', 25_000],
            ['05-11', 'استقطاعات صندوق الجزاءات', '1010', '2050', 15_000],
            ['07-01', 'ضريبة القيمة المضافة المحصلة', '1010', '2090', 55_000],
            ['07-01', 'ض.ق.م مدفوعة على المشتريات', '1270', '1010', 45_000],
            ['08-12', 'ضرائب لصالح الغير مستحقة', '1010', '2080', 20_000],
            ['04-03', 'فتح اعتماد مستندي', '1500', '1110', 200_000],
            ['10-28', 'إقفال اعتماد مستندي', '1510', '1500', 120_000],
            ['05-05', 'خطابات ضمان صادرة وغطاؤها', '1240', '2060', 300_000],

            // ═══ أنشطة الاستثمار ═══
            ['06-15', 'شراء ماكينة قص وثني جديدة', '1430', '1110', 350_000],

            // ═══ أنشطة التمويل ═══
            ['01-20', 'زيادة رأس المال نقداً', '1110', '3020', 500_000],
            ['12-15', 'مسحوبات جاري شركاء', '3040', '1010', 180_000],

            // ═══ خارج المعادلات — حاشية سلايد 6 ═══
            ['09-09', 'شيكات ضمان جديدة صادرة للعملاء', '1022', '2061', 100_000],
        ] as $index => [$monthDay, $description, $debitCode, $creditCode, $amount]) {
            $this->postEntry(
                date: "{$y}-{$monthDay}",
                description: $description,
                debitCode: $debitCode,
                creditCode: $creditCode,
                amount: (float) $amount,
                sequence: $index + 1,
            );
        }

        // A DRAFT entry: large enough that if the statements ever counted
        // unposted work, the numbers would visibly break.
        $this->buildEntry(
            date: "{$y}-12-31",
            description: 'قيد تحت المراجعة — لم يُرحَّل بعد (يجب ألا يظهر في القوائم)',
            debitCode: '5030',
            creditCode: '1010',
            amount: 999_000,
            sequence: 900,
        );

        // An entry in the FOLLOWING year: proves the period filter, and gives
        // the balance sheet something to change when the date is moved.
        $this->postEntry(
            date: ($y + 1) . '-02-14',
            description: 'مبيعات السنة التالية (خارج فترة ' . $y . ')',
            debitCode: '1200',
            creditCode: '4010',
            amount: 450_000,
            sequence: 901,
        );
    }

    /**
     * دفتر الأطراف — سلايد 7. Two deliberately different states:
     *
     *   Customers  — the sub-ledger sums exactly to the control account
     *                (1,315,000), so the balance sheet shows a clean split with
     *                no reconciliation row.
     *   Suppliers  — the sub-ledger is 40,000 short of the control account, so
     *                the reconciliation row appears and can be seen working.
     */
    private function seedPartySubLedgers(): void
    {
        // Customer control 1200 closes at:
        //   480,000 + 6,500,000 − 250,000 − 5,200,000 − 150,000 − 65,000
        // = 1,315,000. The four debit parties less the two advances match it.
        $customers = [
            ['شركة النيل للمقاولات الكهربائية', 620_000],
            ['المجموعة المصرية للطاقة', 430_000],
            ['الشركة العربية للتوريدات الصناعية', 300_000],
            ['مصنع الدلتا للأسمنت', 150_000],
            // دفعات مقدمة — أرصدة دائنة تنتقل إلى الالتزامات المتداولة
            ['هيئة كهرباء القاهرة الكبرى', -120_000],
            ['شركة السويس للبتروكيماويات', -65_000],
        ];

        foreach ($customers as [$name, $balance]) {
            $customer = Customer::firstOrCreate(['name' => $name], [
                'contact_person' => 'إدارة المشتريات',
                'phone' => '01000000000',
            ]);

            $this->partyEntry($customer, $balance, AccountDirection::Debit);
        }

        // Supplier control 2010 closes at 350,000 + 3,800,000 − 3,500,000
        // = 650,000 credit. The sub-ledger below nets to 610,000 credit, so a
        // 40,000 reconciliation row is expected — on purpose.
        $suppliers = [
            ['الشركة المتحدة للكابلات', 380_000],
            ['النصر للصناعات الكهربائية', 220_000],
            ['مصر للمواسير المعدنية', 95_000],
            // دفعات مقدمة للموردين — أرصدة مدينة تنتقل إلى الأصول المتداولة
            ['التقنية الحديثة للأدوات', -55_000],
            ['الأمانة لقطع الغيار', -30_000],
        ];

        foreach ($suppliers as [$name, $balance]) {
            $supplier = Supplier::firstOrCreate(['name' => $name], [
                'contact_person' => 'إدارة المبيعات',
                'phone' => '01100000000',
            ]);

            $this->partyEntry($supplier, $balance, AccountDirection::Credit);
        }
    }

    /**
     * One sub-ledger posting carrying the party's whole balance. `amount` is
     * signed in the party's own natural direction (see
     * PartyReclassificationService), so a negative figure here is an advance:
     * a prepayment received from a customer, or one paid to a supplier.
     */
    private function partyEntry(mixed $party, float $amount, AccountDirection $naturalSide): void
    {
        AccountEntry::create([
            'party_type' => $party->getMorphClass(),
            'party_id' => $party->getKey(),
            'entry_date' => self::YEAR . '-12-31',
            'direction' => $amount >= 0
                ? $naturalSide
                : ($naturalSide === AccountDirection::Debit ? AccountDirection::Credit : AccountDirection::Debit),
            'amount' => $amount,
            'notes' => self::MARKER . ' — رصيد تجريبي',
        ]);
    }

    private function postEntry(
        string $date,
        string $description,
        string $debitCode,
        string $creditCode,
        float $amount,
        int $sequence,
    ): void {
        $entry = $this->buildEntry($date, $description, $debitCode, $creditCode, $amount, $sequence);

        $entry?->update([
            'status' => JournalStatus::Posted,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'posted_at' => now(),
        ]);
    }

    /**
     * Build a balanced two-line entry. Returns null (and skips silently) when
     * either account is missing from the chart, so a company that renumbered
     * its accounts gets a partial demo rather than a crash.
     */
    private function buildEntry(
        string $date,
        string $description,
        string $debitCode,
        string $creditCode,
        float $amount,
        int $sequence,
    ): ?JournalEntry {
        $debit = $this->account($debitCode);
        $credit = $this->account($creditCode);

        if ($debit === null || $credit === null) {
            return null;
        }

        $entry = JournalEntry::create([
            'entry_number' => DocumentType::Settlement->prefix() . '-' . str_replace('-', '', substr($date, 0, 7)) . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'document_number' => self::MARKER . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'document_type' => DocumentType::Settlement,
            'entry_date' => $date,
            'description' => $description,
            'status' => JournalStatus::Draft,
            'currency' => 'EGP',
        ]);

        $entry->lines()->create([
            'account_id' => $debit->id,
            'direction' => AccountDirection::Debit,
            'amount' => $amount,
        ]);

        $entry->lines()->create([
            'account_id' => $credit->id,
            'direction' => AccountDirection::Credit,
            'amount' => $amount,
        ]);

        return $entry;
    }

    private function account(string $code): ?Account
    {
        return $this->accounts[$code] ?? null;
    }
}
