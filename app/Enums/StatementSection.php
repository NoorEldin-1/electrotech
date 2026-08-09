<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * بند القوائم المالية — where a chart-of-accounts entry appears in the four
 * financial statements that follow the trial balance (ماليات.pptx).
 *
 * This is a PRESENTATION axis, deliberately separate from {@see AccountType}:
 * the type decides the natural debit/credit side and drives posting, the
 * ledger and the trial balance, and must not change. But the type is far too
 * coarse to build a statement from — every asset shares one type, so it cannot
 * tell a fixed asset from cash from a receivable, which is exactly the
 * distinction the balance sheet (سلايد 4) and the cash-flow statement
 * (سلايد 9) are built on. Same on the expense side: سلايد 2 puts operating,
 * installation and export expenses inside cost of sales while سلايد 3 keeps
 * general/administrative and interest expenses on separate lines below gross
 * profit.
 *
 * An account with no section falls back to {@see self::defaultForType()}, so a
 * statement never silently drops an unclassified account.
 */
enum StatementSection: string implements HasLabel, HasColor
{
    // ----- قائمة التشغيل (سلايد 2) — يتجمّع منها «تكلفة المبيعات» -----

    /** تكلفة البضاعة المباعة، م.تشغيل، م.تركيب، م.تصدير، م.وإهلاكات صناعية. */
    case CostOfSales = 'cost_of_sales';

    // ----- قائمة الدخل (سلايد 3) -----

    /** المبيعات. */
    case Sales = 'sales';

    /** مردودات المبيعات — حساب مقابل يُطرح للوصول إلى صافي المبيعات. */
    case SalesReturns = 'sales_returns';

    /** إيرادات متنوعة. */
    case OtherRevenue = 'other_revenue';

    /**
     * فروق العملة. يظهر في سلايد 3 مرتين — مرة بالإضافة ومرة بالطرح — لأن
     * الحساب نفسه قد يقفل مديناً أو دائناً. القائمة توجّهه حسب إشارة رصيده.
     */
    case FxDifferences = 'fx_differences';

    /** أرباح رأسمالية — إيراد في قائمة الدخل، وتسوية صريحة في التدفقات (سلايد 9). */
    case CapitalGains = 'capital_gains';

    /** مصروفات عمومية وإدارية. */
    case GeneralAdminExpenses = 'general_admin_expenses';

    /** مصروفات وإهلاكات (غير الصناعية التي تدخل تكلفة المبيعات). */
    case DepreciationExpenses = 'depreciation_expenses';

    /** اعتمادات مستندية سبق إقفالها. */
    case ClosedLettersOfCredit = 'closed_letters_of_credit';

    /** فوائد مدينة وأعباء تمويلية. */
    case FinanceCost = 'finance_cost';

    // ----- قائمة المركز المالي (سلايدات 4–6) -----

    /** الأصول الثابتة بالتكلفة: أراضٍ، مبانٍ، معدات وآلات، أجهزة وأثاث. */
    case FixedAssets = 'fixed_assets';

    /** مجمع الإهلاك — حساب مقابل يُخصم من تكلفة الأصل الثابت المرتبط به. */
    case AccumulatedDepreciation = 'accumulated_depreciation';

    /** أصول متداولة عدا النقدية: مخزون، عملاء، أرصدة مدينة، أوراق قبض… */
    case CurrentAssets = 'current_assets';

    /**
     * أرصدة بالبنوك والصندوق. أصل متداول، لكنه مفصول لأن قائمة التدفقات
     * النقدية تحتاج تعريفاً دقيقاً لـ«النقدية» تطابق عليه رصيد آخر الفترة.
     */
    case CashAndBanks = 'cash_and_banks';

    /** التزامات متداولة: موردون، أوراق دفع، ضرائب، تسهيلات، أرصدة دائنة… */
    case CurrentLiabilities = 'current_liabilities';

    /** الاحتياطيات والمخصصات — ضمن التمويل في المركز المالي، وتسوية غير نقدية في التدفقات. */
    case Provisions = 'provisions';

    /** حقوق الملكية: رأس المال. */
    case Equity = 'equity';

    /** جاري شركاء — يظهر في التمويل بقائمة التدفقات (مسحوبات جاري شركاء). */
    case PartnersCurrentAccount = 'partners_current_account';

    // ----- خارج المعادلات -----

    /**
     * «حساب يخرج من المعادلات» (سلايد 4). أوضح مثال: ح/صافي الربح — ربح الفترة
     * يُحسب من قائمة الدخل، فإدراج الحساب أيضاً يضاعفه.
     */
    case Excluded = 'excluded';

    /**
     * خارج المعادلات لكنه يُطبع كحاشية أسفل القائمة، تنفيذاً لحاشية سلايد 6:
     * «يوجد شيكات ضمانه بمبلغ 000». خطابات وشيكات الضمان النموذج المعتاد.
     */
    case Memo = 'memo';

    public function getLabel(): string
    {
        return __('resources.enums.statement_section.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Sales, self::OtherRevenue, self::CapitalGains => 'success',
            self::SalesReturns, self::CostOfSales, self::GeneralAdminExpenses,
            self::DepreciationExpenses, self::ClosedLettersOfCredit, self::FinanceCost => 'danger',
            self::FxDifferences => 'warning',
            self::FixedAssets, self::CurrentAssets, self::CashAndBanks => 'info',
            self::AccumulatedDepreciation, self::CurrentLiabilities => 'warning',
            self::Provisions, self::Equity, self::PartnersCurrentAccount => 'primary',
            self::Excluded, self::Memo => 'gray',
        };
    }

    /**
     * The safe fallback for an account the accountant has not classified yet.
     * Chosen so an unclassified account still lands somewhere defensible and
     * keeps the balance sheet in balance, rather than vanishing.
     */
    public static function defaultForType(AccountType $type): self
    {
        return match ($type) {
            AccountType::Asset => self::CurrentAssets,
            AccountType::Liability => self::CurrentLiabilities,
            AccountType::Equity => self::Equity,
            AccountType::Revenue => self::OtherRevenue,
            AccountType::Expense => self::GeneralAdminExpenses,
        };
    }

    /** Sections that make up قائمة التشغيل / تكلفة المبيعات. */
    public function isCostOfSales(): bool
    {
        return $this === self::CostOfSales;
    }

    /** Appears on the income statement (سلايد 3) rather than the balance sheet. */
    public function isIncomeStatement(): bool
    {
        return in_array($this, [
            self::Sales,
            self::SalesReturns,
            self::CostOfSales,
            self::OtherRevenue,
            self::FxDifferences,
            self::CapitalGains,
            self::GeneralAdminExpenses,
            self::DepreciationExpenses,
            self::ClosedLettersOfCredit,
            self::FinanceCost,
        ], true);
    }

    /** Appears on the balance sheet (سلايدات 4–6). */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [
            self::FixedAssets,
            self::AccumulatedDepreciation,
            self::CurrentAssets,
            self::CashAndBanks,
            self::CurrentLiabilities,
            self::Provisions,
            self::Equity,
            self::PartnersCurrentAccount,
        ], true);
    }

    /**
     * Where the section's period movement lands in the cash-flow statement
     * (سلايد 9). `null` means it plays no direct part — either it is an income
     * statement section already folded into net profit, or it is excluded.
     *
     *   working_capital_asset     — النقص (الزيادة) في حساب أصول ⇒ أثر عكسي
     *   working_capital_liability — النقص (الزيادة) في حساب التزامات ⇒ أثر مباشر
     *   non_cash                  — الإهلاك والمخصصات (تسوية على صافي الربح)
     *   capital_gains             — أرباح رأسمالية (تسوية على صافي الربح)
     *   investing                 — أنشطة الاستثمار (أصول ثابتة)
     *   financing                 — أنشطة التمويل (جاري شركاء، رأس المال)
     *   cash                      — النقدية نفسها (طرف المطابقة، لا بند)
     */
    public function cashFlowRole(): ?string
    {
        return match ($this) {
            self::CurrentAssets => 'working_capital_asset',
            self::CurrentLiabilities => 'working_capital_liability',
            self::AccumulatedDepreciation, self::Provisions => 'non_cash',
            self::CapitalGains => 'capital_gains',
            self::FixedAssets => 'investing',
            self::PartnersCurrentAccount, self::Equity => 'financing',
            self::CashAndBanks => 'cash',
            default => null,
        };
    }

    /**
     * Display order inside a statement — lower comes first. Mirrors the row
     * order printed on the slides so the screen reads like the client's sheet.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Sales => 10,
            self::SalesReturns => 20,
            self::CostOfSales => 30,
            self::OtherRevenue => 40,
            self::CapitalGains => 45,
            self::FxDifferences => 50,
            self::GeneralAdminExpenses => 60,
            self::DepreciationExpenses => 70,
            self::ClosedLettersOfCredit => 80,
            self::FinanceCost => 90,
            self::FixedAssets => 100,
            self::AccumulatedDepreciation => 110,
            self::CurrentAssets => 120,
            self::CashAndBanks => 130,
            self::CurrentLiabilities => 140,
            self::Equity => 150,
            self::Provisions => 160,
            self::PartnersCurrentAccount => 170,
            self::Memo => 900,
            self::Excluded => 999,
        };
    }
}
