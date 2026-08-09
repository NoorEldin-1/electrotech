<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountDirection;
use App\Enums\AccountType;
use App\Enums\StatementSection;
use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Seeds the chart of accounts from Financial_Department.md (سلايد 5 — أسماء
 * الحسابات), extended by ماليات.pptx with the accounts the four financial
 * statements need (fixed assets in detail, accumulated depreciation, capital,
 * reserves, partners' current account, sales returns…).
 *
 * Safe to run on every deploy, and deliberately conservative in two ways:
 *
 *  1. Accounts are created with firstOrCreate keyed on `code`, so an account
 *     the admin has since renamed or reclassified is never overwritten.
 *  2. The statement section is BACK-FILLED only where it is still null. An
 *     accountant who moved an account to a different statement line keeps that
 *     choice across deploys.
 *
 * NOTE: the classification (type/nature/section/currency) is the proposed
 * mapping and should be confirmed with the company accountant.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as $definition) {
            $type = $definition['type'];

            $account = Account::firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'type' => $type,
                    'nature' => $definition['nature'] ?? $type->naturalDirection(),
                    'statement_section' => $definition['section'],
                    'currency' => $definition['currency'] ?? 'EGP',
                    'is_active' => true,
                ],
            );

            // Back-fill for charts seeded before ماليات.pptx: classify only
            // what is still unclassified, never re-classify.
            if ($account->statement_section === null) {
                $account->forceFill(['statement_section' => $definition['section']])->save();
            }
        }

        $this->linkAccumulatedDepreciation();
        $this->markPartyControlAccounts();
    }

    /**
     * سلايد 7 — mark the control accounts the balance sheet must split by
     * party balance sign. Only fills accounts that carry no marker yet, so an
     * admin who cleared or changed one keeps that decision.
     */
    private function markPartyControlAccounts(): void
    {
        foreach ((array) config('finance.party_control_accounts', []) as $partyType => $codes) {
            Account::query()
                ->whereIn('code', (array) $codes)
                ->whereNull('party_control')
                ->update(['party_control' => $partyType]);
        }
    }

    /**
     * Point each accumulated-depreciation account at the fixed asset it is
     * deducted from, so the balance sheet can print التكلفة / مجمع الإهلاك /
     * الصافى in three columns (سلايد 6). Idempotent, and never re-points a
     * link an admin has changed by hand.
     *
     * @return void
     */
    private function linkAccumulatedDepreciation(): void
    {
        foreach ($this->depreciationLinks() as $contraCode => $assetCode) {
            $contra = Account::where('code', $contraCode)->first();
            $asset = Account::where('code', $assetCode)->first();

            if ($contra && $asset && $contra->contra_of_account_id === null) {
                $contra->forceFill(['contra_of_account_id' => $asset->id])->save();
            }
        }
    }

    /**
     * مجمع الإهلاك ⇒ الأصل الثابت. Land (1410) is intentionally absent: it is
     * not depreciated.
     *
     * @return array<string, string>
     */
    private function depreciationLinks(): array
    {
        return [
            '1450' => '1420', // مجمع إهلاك المباني        ⇒ المباني
            '1460' => '1430', // مجمع إهلاك معدات وآلات    ⇒ معدات وآلات
            '1470' => '1440', // مجمع إهلاك أجهزة وأثاث    ⇒ أجهزة وأثاث
        ];
    }

    /**
     * @return array<int, array{code:string, name:string, type:AccountType, section:StatementSection, nature?:AccountDirection, currency?:string}>
     */
    private function accounts(): array
    {
        $asset = AccountType::Asset;
        $liability = AccountType::Liability;
        $equity = AccountType::Equity;
        $revenue = AccountType::Revenue;
        $expense = AccountType::Expense;

        $cash = StatementSection::CashAndBanks;
        $currentAsset = StatementSection::CurrentAssets;
        $fixedAsset = StatementSection::FixedAssets;
        $accumDep = StatementSection::AccumulatedDepreciation;
        $currentLiability = StatementSection::CurrentLiabilities;
        $memo = StatementSection::Memo;

        return [
            // ================= الأصول — النقدية وما في حكمها =================
            // قائمة التدفقات النقدية تطابق رصيد آخر الفترة على مجموع هذه
            // الحسابات بالتحديد، فتصنيفها هو تعريف «النقدية» في النظام.
            ['code' => '1010', 'name' => 'الخزينة', 'type' => $asset, 'section' => $cash],
            ['code' => '1011', 'name' => 'خزينة أجنبي', 'type' => $asset, 'section' => $cash, 'currency' => 'USD'],
            ['code' => '1110', 'name' => 'بنك التجاري جنيه', 'type' => $asset, 'section' => $cash],
            ['code' => '1111', 'name' => 'بنك التجاري دولار', 'type' => $asset, 'section' => $cash, 'currency' => 'USD'],
            ['code' => '1112', 'name' => 'بنك التجاري يورو', 'type' => $asset, 'section' => $cash, 'currency' => 'EUR'],
            ['code' => '1120', 'name' => 'بنك وفا جنيه', 'type' => $asset, 'section' => $cash],
            ['code' => '1121', 'name' => 'بنك وفا دولار', 'type' => $asset, 'section' => $cash, 'currency' => 'USD'],
            ['code' => '1122', 'name' => 'بنك وفا يورو', 'type' => $asset, 'section' => $cash, 'currency' => 'EUR'],
            ['code' => '1130', 'name' => 'بنك البركة جنيه', 'type' => $asset, 'section' => $cash],
            ['code' => '1131', 'name' => 'بنك البركة دولار', 'type' => $asset, 'section' => $cash, 'currency' => 'USD'],
            ['code' => '1140', 'name' => 'بنك أبو ظبي الأول جنيه', 'type' => $asset, 'section' => $cash],
            ['code' => '1141', 'name' => 'بنك أبو ظبي الأول دولار', 'type' => $asset, 'section' => $cash, 'currency' => 'USD'],
            ['code' => '1142', 'name' => 'بنك أبو ظبي الأول يورو', 'type' => $asset, 'section' => $cash, 'currency' => 'EUR'],
            ['code' => '1150', 'name' => 'بنك عودة جنيه', 'type' => $asset, 'section' => $cash],
            ['code' => '1160', 'name' => 'بنك الأهلي جنيه', 'type' => $asset, 'section' => $cash],

            // ================= الأصول المتداولة =================
            ['code' => '1020', 'name' => 'شيكات بالخزينة', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1021', 'name' => 'شيكات تحت التحصيل', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1200', 'name' => 'العملاء', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1210', 'name' => 'مدينون', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1230', 'name' => 'أوراق قبض', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1240', 'name' => 'خطابات الضمان', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1250', 'name' => 'أرصدة مدينة أخرى', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1270', 'name' => 'مصلحة الضرائب على المبيعات - رصيد مدين', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1300', 'name' => 'المخزون', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1500', 'name' => 'اعتمادات', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1510', 'name' => 'اعتمادات تم إقفالها', 'type' => $asset, 'section' => $currentAsset],
            ['code' => '1600', 'name' => 'ا.ت.ص من المنبع', 'type' => $asset, 'section' => $currentAsset],

            // ================= الأصول طويلة الأجل (سلايد 6) =================
            // 1400 هو الحساب الرئيسي القديم؛ التفصيل أدناه يقع تحته كأبناء
            // فتُقرأ القائمة كما في سلايد 5: الرئيسي يظهر والفرعي بداخله.
            ['code' => '1400', 'name' => 'أصول ثابتة', 'type' => $asset, 'section' => $fixedAsset],
            ['code' => '1410', 'name' => 'الأراضي', 'type' => $asset, 'section' => $fixedAsset],
            ['code' => '1420', 'name' => 'المباني', 'type' => $asset, 'section' => $fixedAsset],
            ['code' => '1430', 'name' => 'معدات وآلات', 'type' => $asset, 'section' => $fixedAsset],
            ['code' => '1440', 'name' => 'أجهزة وأثاث', 'type' => $asset, 'section' => $fixedAsset],

            // مجمع الإهلاك: حسابات أصول بطبيعة دائنة (مقابلة) — تُخصم من تكلفة
            // الأصل المرتبط بها في عمود مستقل.
            ['code' => '1450', 'name' => 'مجمع إهلاك المباني', 'type' => $asset, 'section' => $accumDep, 'nature' => AccountDirection::Credit],
            ['code' => '1460', 'name' => 'مجمع إهلاك معدات وآلات', 'type' => $asset, 'section' => $accumDep, 'nature' => AccountDirection::Credit],
            ['code' => '1470', 'name' => 'مجمع إهلاك أجهزة وأثاث', 'type' => $asset, 'section' => $accumDep, 'nature' => AccountDirection::Credit],

            // ================= الالتزامات المتداولة =================
            ['code' => '2010', 'name' => 'مورد محلي', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2011', 'name' => 'مورد خارجي', 'type' => $liability, 'section' => $currentLiability, 'currency' => 'USD'],
            ['code' => '2020', 'name' => 'دائنون', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2030', 'name' => 'أوراق دفع', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2040', 'name' => 'مصلحة الضرائب', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2050', 'name' => 'صندوق الجزاءات', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2070', 'name' => 'تسهيلات أبو ظبي', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2080', 'name' => 'ا.ت.ص للغير', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2090', 'name' => 'ق.م (القيمة المضافة)', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2100', 'name' => 'أرصدة دائنة أخرى', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2110', 'name' => 'مخصص العمولات', 'type' => $liability, 'section' => $currentLiability],
            ['code' => '2060', 'name' => 'غطاء خطابات ضمان', 'type' => $liability, 'section' => $currentLiability],

            // ================= خارج المعادلات =================
            // حاشية سلايد 6: «يوجد شيكات ضمانه بمبلغ 000» — تُطبع أسفل القائمة
            // ولا تدخل رأس المال العامل.
            ['code' => '1022', 'name' => 'شيكات ضمان', 'type' => $asset, 'section' => $memo],
            ['code' => '2061', 'name' => 'غطاء شيكات ضمان', 'type' => $liability, 'section' => $memo],

            // ================= حقوق الملكية (سلايد 6) =================
            // 3010 صافي الربح يخرج من المعادلات عمداً: أرباح الفترة تُحسب من
            // قائمة الدخل، فإدراج الحساب أيضاً يضاعفها.
            ['code' => '3010', 'name' => 'صافي الربح', 'type' => $equity, 'section' => StatementSection::Excluded],
            ['code' => '3020', 'name' => 'رأس المال', 'type' => $equity, 'section' => StatementSection::Equity],
            ['code' => '3030', 'name' => 'الاحتياطيات والمخصصات', 'type' => $equity, 'section' => StatementSection::Provisions],
            ['code' => '3040', 'name' => 'جاري شركاء', 'type' => $equity, 'section' => StatementSection::PartnersCurrentAccount],

            // ================= الإيرادات (سلايد 3) =================
            ['code' => '4010', 'name' => 'المبيعات', 'type' => $revenue, 'section' => StatementSection::Sales],
            // مردودات المبيعات: إيراد بطبيعة مدينة (حساب مقابل) — يُطرح من
            // المبيعات للوصول إلى صافي المبيعات.
            ['code' => '4060', 'name' => 'مردودات المبيعات', 'type' => $revenue, 'section' => StatementSection::SalesReturns, 'nature' => AccountDirection::Debit],
            ['code' => '4020', 'name' => 'إيرادات متنوعة', 'type' => $revenue, 'section' => StatementSection::OtherRevenue],
            ['code' => '4030', 'name' => 'فوائد دائنة', 'type' => $revenue, 'section' => StatementSection::OtherRevenue],
            ['code' => '4040', 'name' => 'فروق عملة', 'type' => $revenue, 'section' => StatementSection::FxDifferences],
            ['code' => '4050', 'name' => 'أرباح رأسمالية', 'type' => $revenue, 'section' => StatementSection::CapitalGains],

            // ================= المصروفات =================
            // قائمة التشغيل (سلايد 2) — كل ما يتجمّع في «تكلفة المبيعات».
            ['code' => '5070', 'name' => 'تكلفة البضاعة المباعة', 'type' => $expense, 'section' => StatementSection::CostOfSales],
            ['code' => '5010', 'name' => 'مصروفات تشغيل', 'type' => $expense, 'section' => StatementSection::CostOfSales],
            ['code' => '5020', 'name' => 'مصروفات تركيب', 'type' => $expense, 'section' => StatementSection::CostOfSales],
            ['code' => '5050', 'name' => 'مصروفات تصدير', 'type' => $expense, 'section' => StatementSection::CostOfSales],
            ['code' => '5100', 'name' => 'مصروفات وإهلاكات صناعية', 'type' => $expense, 'section' => StatementSection::CostOfSales],
            ['code' => '5060', 'name' => 'هالك التصنيع', 'type' => $expense, 'section' => StatementSection::CostOfSales],

            // ما دون مجمل الربح في قائمة الدخل (سلايد 3).
            ['code' => '5030', 'name' => 'مصروفات عمومية', 'type' => $expense, 'section' => StatementSection::GeneralAdminExpenses],
            ['code' => '5110', 'name' => 'مصروفات وإهلاكات', 'type' => $expense, 'section' => StatementSection::DepreciationExpenses],
            ['code' => '5080', 'name' => 'اعتمادات مستندية سبق إقفالها', 'type' => $expense, 'section' => StatementSection::ClosedLettersOfCredit],
            ['code' => '5040', 'name' => 'مصروفات تمويلية', 'type' => $expense, 'section' => StatementSection::FinanceCost],
            ['code' => '5090', 'name' => 'فوائد مدينة', 'type' => $expense, 'section' => StatementSection::FinanceCost],
        ];
    }
}
