<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Seeds the chart of accounts from Financial_Department.md (سلايد 5 — أسماء
 * الحسابات). Safe to run on every deploy: uses firstOrCreate keyed on `code`,
 * so it only adds accounts that are missing and NEVER overwrites accounts the
 * admin has since edited (name/type/nature/currency/is_active are preserved).
 * Nature is derived from the account type; currency follows the bank/treasury
 * currency.
 *
 * NOTE: the classification (type/nature/currency) is the proposed mapping from
 * the plan (جدول 1.1) and should be confirmed with the company accountant.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as [$code, $name, $type, $currency]) {
            Account::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'nature' => $type->naturalDirection(),
                    'currency' => $currency,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return array<int, array{0:string,1:string,2:AccountType,3:string}>
     */
    private function accounts(): array
    {
        $asset = AccountType::Asset;
        $liability = AccountType::Liability;
        $equity = AccountType::Equity;
        $revenue = AccountType::Revenue;
        $expense = AccountType::Expense;

        return [
            // ----- الأصول (Assets) — خزينة وشيكات -----
            ['1010', 'الخزينة', $asset, 'EGP'],
            ['1011', 'خزينة أجنبي', $asset, 'USD'],
            ['1020', 'شيكات بالخزينة', $asset, 'EGP'],
            ['1021', 'شيكات تحت التحصيل', $asset, 'EGP'],
            ['1022', 'شيكات ضمان', $asset, 'EGP'],

            // ----- الأصول — البنوك (Banks) -----
            ['1110', 'بنك التجاري جنيه', $asset, 'EGP'],
            ['1111', 'بنك التجاري دولار', $asset, 'USD'],
            ['1112', 'بنك التجاري يورو', $asset, 'EUR'],
            ['1120', 'بنك وفا جنيه', $asset, 'EGP'],
            ['1121', 'بنك وفا دولار', $asset, 'USD'],
            ['1122', 'بنك وفا يورو', $asset, 'EUR'],
            ['1130', 'بنك البركة جنيه', $asset, 'EGP'],
            ['1131', 'بنك البركة دولار', $asset, 'USD'],
            ['1140', 'بنك أبو ظبي الأول جنيه', $asset, 'EGP'],
            ['1141', 'بنك أبو ظبي الأول دولار', $asset, 'USD'],
            ['1142', 'بنك أبو ظبي الأول يورو', $asset, 'EUR'],
            ['1150', 'بنك عودة جنيه', $asset, 'EGP'],
            ['1160', 'بنك الأهلي جنيه', $asset, 'EGP'],

            // ----- الأصول — حسابات مراقبة وأصول أخرى -----
            ['1200', 'العملاء', $asset, 'EGP'],
            ['1210', 'مدينون', $asset, 'EGP'],
            ['1300', 'المخزون', $asset, 'EGP'],
            ['1400', 'أصول ثابتة', $asset, 'EGP'],
            ['1500', 'اعتمادات', $asset, 'EGP'],
            ['1510', 'اعتمادات تم إقفالها', $asset, 'EGP'],
            ['1600', 'ا.ت.ص من المنبع', $asset, 'EGP'],

            // ----- الالتزامات (Liabilities) -----
            ['2010', 'مورد محلي', $liability, 'EGP'],
            ['2011', 'مورد خارجي', $liability, 'USD'],
            ['2020', 'دائنون', $liability, 'EGP'],
            ['2030', 'أوراق دفع', $liability, 'EGP'],
            ['2040', 'مصلحة الضرائب', $liability, 'EGP'],
            ['2050', 'صندوق الجزاءات', $liability, 'EGP'],
            ['2060', 'غطاء خطابات ضمان', $liability, 'EGP'],
            ['2061', 'غطاء شيكات ضمان', $liability, 'EGP'],
            ['2070', 'تسهيلات أبو ظبي', $liability, 'EGP'],
            ['2080', 'ا.ت.ص للغير', $liability, 'EGP'],
            ['2090', 'ق.م (القيمة المضافة)', $liability, 'EGP'],

            // ----- حقوق الملكية (Equity) -----
            ['3010', 'صافي الربح', $equity, 'EGP'],

            // ----- الإيرادات (Revenue) -----
            ['4010', 'المبيعات', $revenue, 'EGP'],
            ['4020', 'إيرادات متنوعة', $revenue, 'EGP'],
            ['4030', 'فوائد دائنة', $revenue, 'EGP'],
            ['4040', 'فروق عملة', $revenue, 'EGP'],

            // ----- المصروفات (Expenses) -----
            ['5010', 'مصروفات تشغيل', $expense, 'EGP'],
            ['5020', 'مصروفات تركيب', $expense, 'EGP'],
            ['5030', 'مصروفات عمومية', $expense, 'EGP'],
            ['5040', 'مصروفات تمويلية', $expense, 'EGP'],
            ['5050', 'مصروفات تصدير', $expense, 'EGP'],
        ];
    }
}
