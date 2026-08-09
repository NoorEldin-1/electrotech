<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The table of contents of the public documentation page (/documentation).
 *
 * Single source of truth: the sidebar, the hero counters and the JSON-LD
 * structured data are all rendered from this array, so a new section can
 * never end up documented but unreachable (or listed but missing).
 *
 * Every entry:
 *   id    — must match the `id` attribute of the rendered <section>
 *   label — the sidebar text (Arabic)
 *   kw    — extra search keywords (English names, synonyms, screen labels)
 *           so the sidebar search finds a screen however the reader spells it
 */
final class DocumentationOutline
{
    /**
     * @return array<int, array{
     *     id: string,
     *     label: string,
     *     no: string,
     *     icon: string,
     *     dept: string,
     *     items: array<int, array{id: string, label: string, kw: string}>
     * }>
     */
    public static function groups(): array
    {
        return [
            [
                'id' => 'g-start',
                'label' => 'ابدأ من هنا',
                'no' => 'تمهيد',
                'icon' => 'compass',
                'dept' => 'var(--brand-500)',
                'items' => [
                    ['id' => 'intro', 'label' => 'إيه هي المنصة دي؟', 'kw' => 'مقدمة introduction about نظرة عامة'],
                    ['id' => 'how-to-read', 'label' => 'إزاي تقرأ الدليل', 'kw' => 'how to read guide شرح الرموز'],
                    ['id' => 'big-picture', 'label' => 'رحلة العملية كاملة', 'kw' => 'flow دورة حياة lifecycle خريطة'],
                    ['id' => 'core-concepts', 'label' => 'مفاهيم لازم تفهمها', 'kw' => 'concepts مركز تكلفة مسودة ترحيل مخازن'],
                    ['id' => 'ui-tour', 'label' => 'جولة في شكل الشاشة', 'kw' => 'واجهة interface sidebar topbar جدول'],
                    ['id' => 'dashboard', 'label' => 'لوحة التحكم', 'kw' => 'dashboard الرئيسية إحصائيات widgets'],
                    ['id' => 'common-actions', 'label' => 'الأزرار المشتركة', 'kw' => 'common actions إضافة تعديل حذف بحث فلتر تصدير'],
                ],
            ],
            [
                'id' => 'g-general',
                'label' => 'الإدارة العامة',
                'no' => 'القسم ١',
                'icon' => 'building',
                'dept' => 'var(--dept-general)',
                'items' => [
                    ['id' => 'operations-overview', 'label' => 'نظرة عامة على العمليات', 'kw' => 'operations overview متابعة العمليات النشطة'],
                    ['id' => 'operation-cost', 'label' => 'مركز تكلفة العملية', 'kw' => 'cost center تكلفة ربح هامش إقفال cogs'],
                    ['id' => 'supply-orders-file', 'label' => 'ملف أوامر التوريد', 'kw' => 'supply orders file أوامر الشراء للعملية'],
                    ['id' => 'delivery-minutes', 'label' => 'محاضر التسليم', 'kw' => 'delivery minutes محضر توزيع'],
                    ['id' => 'financial-claims', 'label' => 'المطالبات المالية', 'kw' => 'financial claims مطالبة تحصيل'],
                    ['id' => 'operation-payments', 'label' => 'الدفعات والمقبوضات', 'kw' => 'payments دفعة مقبوضات نقدي شيك تحويل'],
                    ['id' => 'credit-facilities', 'label' => 'التسهيلات الائتمانية', 'kw' => 'credit facilities تسهيل سقف بنك'],
                    ['id' => 'installations', 'label' => 'التركيبات', 'kw' => 'installations تركيب موقع'],
                    ['id' => 'site-surveys', 'label' => 'معاينات الموقع', 'kw' => 'site surveys معاينة مقاسات'],
                ],
            ],
            [
                'id' => 'g-sales',
                'label' => 'المبيعات وإدارة العملاء',
                'no' => 'القسم ٢',
                'icon' => 'briefcase',
                'dept' => 'var(--dept-sales)',
                'items' => [
                    ['id' => 'projects', 'label' => 'المشاريع (العمليات)', 'kw' => 'projects مشروع عملية إنشاء'],
                    ['id' => 'project-offers', 'label' => 'العروض (BOQ)', 'kw' => 'offers عرض سعر quotation boq ضريبة'],
                    ['id' => 'tender-projects', 'label' => 'عمليات المناقصات', 'kw' => 'tender مناقصة تنبيه alarm'],
                    ['id' => 'in-hand-projects', 'label' => 'العمليات تحت اليد', 'kw' => 'in hand تحت اليد smb submittal موافقة المدير'],
                    ['id' => 'active-projects', 'label' => 'العمليات النشطة', 'kw' => 'active projects نشطة تنفيذ'],
                    ['id' => 'lost-projects', 'label' => 'قائمة المفقودة', 'kw' => 'lost خسارة منافس'],
                    ['id' => 'customers', 'label' => 'العملاء', 'kw' => 'customers عميل كشف حساب'],
                ],
            ],
            [
                'id' => 'g-pmo',
                'label' => 'مكتب إدارة المشروعات',
                'no' => 'القسم ٣',
                'icon' => 'blueprint',
                'dept' => 'var(--dept-pmo)',
                'items' => [
                    ['id' => 'boms', 'label' => 'قوائم المواد', 'kw' => 'bom قائمة مواد هالك تركيبة قياسية'],
                    ['id' => 'work-orders', 'label' => 'أوامر التصنيع', 'kw' => 'work order أمر تشغيل تصنيع جودة اعتماد'],
                ],
            ],
            [
                'id' => 'g-procurement',
                'label' => 'المشتريات',
                'no' => 'القسم ٤',
                'icon' => 'cart',
                'dept' => 'var(--dept-procurement)',
                'items' => [
                    ['id' => 'items', 'label' => 'الأصناف', 'kw' => 'items صنف sku وحدة حد أدنى'],
                    ['id' => 'suppliers', 'label' => 'الموردون', 'kw' => 'suppliers مورد ضريبة إعفاء'],
                    ['id' => 'purchase-orders', 'label' => 'أوامر الشراء', 'kw' => 'purchase order po استلام اعتماد طباعة'],
                    ['id' => 'stock-reservations', 'label' => 'حجوزات المخزون', 'kw' => 'reservation حجز تحرير متاح'],
                ],
            ],
            [
                'id' => 'g-warehouse',
                'label' => 'المستودع',
                'no' => 'القسم ٥',
                'icon' => 'warehouse',
                'dept' => 'var(--dept-warehouse)',
                'items' => [
                    ['id' => 'inventory-transactions', 'label' => 'حركات المخزون', 'kw' => 'inventory transactions حركة وارد صادر'],
                    ['id' => 'stock-card', 'label' => 'كرت الصنف', 'kw' => 'stock card متوسط مرجح متحرك رصيد'],
                    ['id' => 'addition-vouchers', 'label' => 'أذون الإضافة', 'kw' => 'addition voucher إذن إضافة فاتورة مورد'],
                    ['id' => 'issue-vouchers', 'label' => 'أذون الصرف', 'kw' => 'issue voucher إذن صرف خامات'],
                    ['id' => 'return-vouchers', 'label' => 'أذون الارتداد', 'kw' => 'return voucher ارتداد فضلات'],
                    ['id' => 'depreciation-vouchers', 'label' => 'أذون الإهلاك', 'kw' => 'depreciation هالك طبيعي غير طبيعي'],
                    ['id' => 'delivery-vouchers', 'label' => 'أذون التسليم', 'kw' => 'delivery voucher إذن تسليم اعتماد مزدوج'],
                ],
            ],
            [
                'id' => 'g-manufacturing',
                'label' => 'التصنيع',
                'no' => 'القسم ٦',
                'icon' => 'cog',
                'dept' => 'var(--dept-manufacturing)',
                'items' => [
                    ['id' => 'quality-sheets', 'label' => 'أوراق الجودة', 'kw' => 'quality sheet اختبار عزل استمرارية اعتماد'],
                    ['id' => 'production-entries', 'label' => 'الإنتاج والفاقد', 'kw' => 'production فاقد نسبة مقارنة'],
                ],
            ],
            [
                'id' => 'g-finance',
                'label' => 'الإدارة المالية',
                'no' => 'القسم ٧',
                'icon' => 'calculator',
                'dept' => 'var(--dept-finance)',
                'items' => [
                    ['id' => 'accounts', 'label' => 'شجرة الحسابات', 'kw' => 'chart of accounts حساب كود طبيعة'],
                    ['id' => 'journal-entries', 'label' => 'قيود اليومية', 'kw' => 'journal entry قيد مدين دائن ترحيل'],
                    ['id' => 'journal-daybook', 'label' => 'اليومية التحليلية', 'kw' => 'daybook يومية تحليلية أعمدة'],
                    ['id' => 'account-entries', 'label' => 'دفتر الحسابات', 'kw' => 'account entries كشف حساب مورد عميل'],
                    ['id' => 'general-ledger-report', 'label' => 'كشف حساب (دفتر الأستاذ)', 'kw' => 'general ledger أستاذ رصيد'],
                    ['id' => 'trial-balance', 'label' => 'ميزان المراجعة', 'kw' => 'trial balance ميزان توازن'],
                    ['id' => 'operating-statement', 'label' => 'قائمة التشغيل', 'kw' => 'operating statement تكلفة المبيعات تكلفة البضاعة المباعة قوائم مالية'],
                    ['id' => 'income-statement', 'label' => 'قائمة الدخل', 'kw' => 'income statement p&l صافي الربح مجمل الربح ارباح خسائر قوائم مالية'],
                    ['id' => 'balance-sheet', 'label' => 'قائمة المركز المالي', 'kw' => 'balance sheet ميزانية راس المال العامل اجمالي الاستثمار اصول التزامات حقوق ملكية'],
                    ['id' => 'cash-flow-statement', 'label' => 'قائمة التدفقات النقدية', 'kw' => 'cash flow تدفقات نقدية سيولة نقدية مطابقة الخزينة'],
                    ['id' => 'sales-invoices', 'label' => 'فواتير المبيعات', 'kw' => 'sales invoice فاتورة مبيعات مطابقة'],
                ],
            ],
            [
                'id' => 'g-system',
                'label' => 'النظام والصلاحيات',
                'no' => 'القسم ٨',
                'icon' => 'shield',
                'dept' => 'var(--dept-system)',
                'items' => [
                    ['id' => 'users', 'label' => 'المستخدمون', 'kw' => 'users مستخدم كلمة مرور دور'],
                    ['id' => 'roles', 'label' => 'الأدوار والصلاحيات', 'kw' => 'roles permissions دور صلاحية'],
                    ['id' => 'activity-log', 'label' => 'سجل النشاط', 'kw' => 'activity log تدقيق audit من عدّل'],
                ],
            ],
            [
                'id' => 'g-appendix',
                'label' => 'ملاحق ومراجع',
                'no' => 'ملحق',
                'icon' => 'book',
                'dept' => 'var(--brand-500)',
                'items' => [
                    ['id' => 'glossary-statuses', 'label' => 'قاموس كل الحالات', 'kw' => 'statuses حالات ألوان badges'],
                    ['id' => 'attachments', 'label' => 'المرفقات والملفات', 'kw' => 'attachments مرفق رفع تنزيل فئة'],
                    ['id' => 'notifications', 'label' => 'الإشعارات والتنبيهات', 'kw' => 'notifications جرس تنبيه'],
                    ['id' => 'printing', 'label' => 'الطباعة والمستندات', 'kw' => 'print pdf طباعة عربي إنجليزي'],
                    ['id' => 'faq', 'label' => 'أسئلة شائعة', 'kw' => 'faq أسئلة مشاكل مش لاقي'],
                ],
            ],
        ];
    }

    /** Total number of documented screens/sections. */
    public static function sectionCount(): int
    {
        return array_sum(array_map(
            static fn (array $group): int => count($group['items']),
            self::groups(),
        ));
    }

    /** Number of department chapters (everything except the intro + appendix). */
    public static function departmentCount(): int
    {
        return count(array_filter(
            self::groups(),
            static fn (array $group): bool => ! in_array($group['id'], ['g-start', 'g-appendix'], true),
        ));
    }
}
