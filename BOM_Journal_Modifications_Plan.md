# خطة تعديلات "قائمة المواد + قيود اليومية" (قائمة المواد.pptx — 3 سلايدات)

> جولة ملاحظات جديدة من العميل. الملف يحتوي 3 سلايدات: BOM، يومية تحليلية بأعمدة حسابات، وكشف حساب الخزينة الشهري.
> المرجع: الصور المرسلة + قراءة الكود الفعلي (Laravel 12 + Filament 3.3).

---

## 1. تفكيك السلايدات (ماذا يطلب العميل بالضبط)

### سلايد 1 — BOM / قائمة المواد (شجرة المنتج)
- تُكتب في القائمة **المنتج التام**، وتلقائياً يتحوّل إلى **الخامات اللازمة** لتصنيعه.
- مثال: كرسي واحد = 3 كجم خشب + 10 مسمار. أمر تشغيل بـ 5 كراسي ⇒ **سحب 15 كجم خشب + 50 مسمار**.
- **قياس الانحراف**: المقارنة بين **أمر التشغيل** (المخطط) و**أوامر الصرف** (الفعلي) **بالخامات** — لو سُحب 16 كجم خشب فالهالك = **1 كجم**.

### سلايد 2 — اليومية التحليلية (Analytical Daybook)
- جدول أعمدته: **التاريخ | رقم القيد | رقم المستند | البيان**، ثم **مجموعة أعمدة لكل حساب** كل منها مقسوم **مدين / دائن** (الصندوق، مصاريف عمومية، مصاريف تشغيل…).
- **رقم القيد** رقم تسلسلي بسيط (64، 65، 66، 67) — **غير** رقم المستند (3140، 3141، 3142، 3143، 160).
- **لون رقم المستند يدل على نوعه**: **أسود = أمر صرف** (صرف من الخزينة)، **أخضر = قيد تسوية**، (وأحمر = إيصال توريد كما في الخطة المالية الأصلية).
- **كل قيد له جانب مدين وجانب دائن**، وقد يكون **متعدد الأطراف**: مثال القيد 67 (مستند 3143) — حوافز المصنع: جزء من الخزينة (2,300) وجزء من البنك، والجانب الدائن حساب **م. تشغيل** (22,600).

### سلايد 3 — كشف حساب الخزينة الشهري (دفتر الأستاذ)
- عنوان الكشف: **«حساب الخزينة — شهر يونيو»** (اسم الحساب + المدة).
- أعمدة: **التاريخ | رقم القيد | رقم المستند | البيان | دائن | مدين | الرصيد**.
- كل قيد يُرحَّل لدفتر الأستاذ الخاص بالحساب **بالبيان والقيمة وجانبه**، و**الرصيد = الرصيد السابق ± الحركة** مع إظهار القيمة الجديدة في كل سطر.

---

## 2. الوضع الحالي في النظام (حقائق من الكود)

| البند | الحالة | الملف |
|---|---|---|
| قائمة مواد قياسية للمنتج التام (Standard BOM + `output_item_id`) | ✅ منفّذ | `app/Models/Bom.php`, migration `2026_07_14_000001` |
| سحب خامات أمر التشغيل تلقائياً من الـ BOM × الكمية المخططة | ✅ منفّذ | `app/Services/WorkOrderMaterialService.php` |
| تقرير الفاقد **بالقيمة** (مخطط مقابل فعلي) لأمر الإنتاج | ✅ منفّذ | `ProductionEntryResource`, `ProductionEntry::loss_value` |
| مقارنة **بالكميات لكل خامة** بين أمر التشغيل وأوامر الصرف | ❌ **فجوة** | — |
| القيد المزدوج (رأس + سطور + ترحيل + توازن) | ✅ منفّذ | `JournalEntry`, `JournalEntryService` |
| أنواع المستند الثلاثة الملونة | ✅ منفّذ (enum) | `App\Enums\DocumentType` |
| **رقم القيد** كتسلسل رقمي بسيط | ❌ **فجوة** — الموجود `entry_number` بصيغة `PV-202607-0001` | `JournalEntry::generateEntryNumber` |
| **رقم المستند** بترقيم تلقائي متسلسل لكل نوع | ❌ **فجوة** — الحقل نص حر اختياري | `journal_entries.document_number` |
| **يومية تحليلية بأعمدة حسابات** | ❌ **فجوة** — الموجود جدول قيود مسطّح فقط | `JournalEntryResource` |
| دفتر الأستاذ لحساب (رصيد افتتاحي + رصيد متحرك) | ⚠️ جزئي — موجود كـ Relation Manager داخل الحساب، **بدون** فلتر مدة ولا طباعة ولا رقم قيد | `LedgerEntriesRelationManager`, `GeneralLedgerService` |
| صفحة كشف حساب مستقلة قابلة للطباعة | ❌ **فجوة** | — |

---

## 3. القرارات التصميمية (اتُّخذت هنا دون انتظار العميل)

1. **`entry_serial` رقم قيد جديد** بدل تغيير `entry_number`: عمود `unsignedInteger` **متسلسل عام** (لا يُصفَّر شهرياً — كما في الصورة 64…69 عبر الشهر)، فريد، يُولَّد بقفل Cache. نُبقي `entry_number` كمعرّف داخلي/بحث (لا كسر لبيانات أو اختبارات قائمة).
2. **`document_number` يُولَّد تلقائياً لكل نوع مستند على حدة** (تسلسل مستقل لأمر الصرف/إيصال التوريد/قيد التسوية، مطابق للصورة: 3140-3143 للصرف و160 للتسوية) مع إبقاء الحقل **قابلاً للتعديل يدوياً** ليطابق المستند الورقي.
3. **لون رقم المستند**: يُعرض بلون نوعه في كل الشاشات والتقارير (أسود/رمادي = أمر صرف، أحمر = إيصال توريد، أخضر = تسوية) بدل الاعتماد على شارة النوع فقط.
4. **أعمدة اليومية التحليلية يختارها المستخدم**: multi-select للحسابات (افتراضياً الحسابات التي عليها حركة في المدة، بحد أقصى 6 لتفادي جدول لا نهائي)، مع عمودَي **إجمالي مدين/دائن** لكل صف حتى لا تضيع أي قيمة خارج الأعمدة المختارة.
5. **المدة الافتراضية** في اليومية والكشف = **الشهر الحالي** (السلايد نفسه شهري: «شهر يونيو»).
6. **القيود المرحّلة فقط** هي التي تظهر في اليومية والكشف (المسودات لا تدخل الدفاتر) — استمرار لسياسة الترحيل القائمة.
7. **الطباعة** عبر mPDF بنفس نمط `QualitySheetPdfController` (شكل عربي سليم على أي استضافة) لا عبر طباعة المتصفح.
8. **مقارنة الخامات** = صافي المصروف: `مجموع سطور أذون الصرف المرحّلة − مجموع سطور أذون الارتداد المرحّلة`، مقابل `work_order_materials`. تُعرض داخل أمر التصنيع كإجراء (Modal) + طباعة.
9. **الصلاحيات**: صلاحية جديدة `journal_daybook.view` لليومية التحليلية؛ صفحة كشف الحساب تعيد استخدام `general_ledger.view`؛ مقارنة الخامات تعيد استخدام `work_orders.view`.

---

## 4. خطة التنفيذ بالمراحل

### المرحلة 1 — ترقيم القيد والمستند (سلايد 2 — الجزء الأول)
- Migration: `add_serial_and_doc_sequence_to_journal_entries` — `entry_serial` (unique, nullable ثم backfill بترتيب `id`).
- `JournalEntry::generateEntrySerial()` + `generateDocumentNumber(DocumentType)` بقفل Cache.
- التوليد التلقائي في `CreateJournalEntry` (وعند الإنشاء البرمجي عبر `creating` hook) مع إمكانية التعديل اليدوي لرقم المستند.
- `JournalEntryResource`: عمود **رقم القيد** (أول عمود بعد التاريخ، bold، sortable/searchable) + تلوين **رقم المستند** بلون نوعه.
- ترجمات ar/en.

### المرحلة 2 — اليومية التحليلية (سلايد 2 — الجزء الثاني)
- `App\Services\JournalDaybookService`: `rows(from, to, accountIds, currency)` ترجع صفوفاً (قيد واحد لكل صف) بخريطة `account_id => [debit, credit]` + إجماليات الصف، وإجماليات الأعمدة، وقائمة الحسابات ذات الحركة.
- `App\Filament\Pages\JournalDaybook` + `resources/views/filament/pages/journal-daybook.blade.php` (نمط `trial-balance` مع `.et-report` وتوكنات الثيم).
- زر **طباعة** → `App\Http\Controllers\JournalDaybookPdfController` + `resources/views/pdf/journal-daybook.blade.php` (A4-Landscape).
- صلاحية `journal_daybook.view` في الـ seeder + توزيعها على Finance/Admin/GM.

### المرحلة 3 — كشف حساب / دفتر الأستاذ المستقل (سلايد 3)
- توسعة `GeneralLedgerService::for()` لإرجاع **رقم القيد** ضمن الصف (موجودة بالفعل بقية الأعمدة).
- `App\Filament\Pages\GeneralLedgerReport` + blade: اختيار الحساب + من/إلى (افتراضي الشهر الحالي) + صف **رصيد أول المدة** + الرصيد المتحرك + صف **الإجمالي** + رصيد آخر المدة.
- عنوان ديناميكي: «حساب {اسم الحساب} — {المدة}».
- طباعة عبر `GeneralLedgerPdfController` + `resources/views/pdf/general-ledger.blade.php`.
- تحديث `LedgerEntriesRelationManager` بعمود رقم القيد لاتساق العرض.

### المرحلة 4 — مقارنة خامات أمر التشغيل بأوامر الصرف (سلايد 1)
- `App\Services\WorkOrderMaterialVarianceService::for(WorkOrder)`: لكل صنف — `planned_quantity`, `issued_quantity`, `returned_quantity`, `net_issued`, `variance_quantity`, `variance_value`, `variance_percentage`؛ + أصناف مصروفة خارج القائمة (planned = 0).
- إجراء `material_variance` في `WorkOrderResource` (Modal جدول ملوّن: زيادة = danger، توفير = success) + طباعة PDF.
- ترجمات + صلاحية عبر `WorkOrderPolicy::view`.

### المرحلة 5 — الاختبارات والتشطيب
- `tests/Feature/Finance/JournalNumberingTest.php` — تسلسل رقم القيد ورقم المستند لكل نوع.
- `tests/Feature/Finance/JournalDaybookTest.php` — تجميع الأعمدة، استبعاد المسودات، الإجماليات.
- `tests/Feature/Finance/GeneralLedgerReportTest.php` — رصيد أول المدة + الرصيد المتحرك ضمن مدة.
- `tests/Feature/Manufacturing/WorkOrderMaterialVarianceTest.php` — 15 مخطط / 16 مصروف = 1 هالك، وأثر الارتداد.
- مراجعة i18n (ar/en) + الثيم الداكن/الفاتح (توكنات `--surface-*`) + `php artisan optimize:clear`.

---

## 5. حالة التنفيذ (تم — 2026-07-25)

| المرحلة | الحالة | الملفات الرئيسية |
|---|---|---|
| 1 — ترقيم القيد والمستند | ✅ منفّذ | `2026_07_25_000001_add_entry_serial_to_journal_entries.php`، `JournalEntry::generateEntrySerial/generateDocumentNumber`، `DocumentType::documentNumberHexColor`، `JournalEntryResource` |
| 2 — اليومية التحليلية | ✅ منفّذ | `JournalDaybookService`، `Filament\Pages\JournalDaybook` + `journal-daybook.blade.php`، `JournalDaybookPdfController` + `pdf/journal-daybook.blade.php` |
| 3 — كشف الحساب المستقل | ✅ منفّذ | `Filament\Pages\GeneralLedgerReport` + `general-ledger-report.blade.php`، `GeneralLedgerPdfController` + `pdf/general-ledger.blade.php`، توسعة `GeneralLedgerService` + عمود رقم القيد في `LedgerEntriesRelationManager` |
| 4 — مقارنة الخامات | ✅ منفّذ | `WorkOrderMaterialVarianceService`، إجراء `material_variance` في `WorkOrderResource` + `filament/work-orders/material-variance.blade.php`، `WorkOrderMaterialVariancePdfController` |
| 5 — الاختبارات والتشطيب | ✅ منفّذ | `JournalNumberingTest`، `JournalDaybookTest`، `GeneralLedgerReportTest`، `WorkOrderMaterialVarianceTest` (17 اختباراً، كلها ناجحة) + ترجمات ar/en + صلاحية `journal_daybook.view` |

**نتيجة الاختبارات الكاملة**: 403 ناجح / 3 فاشل، والثلاثة **سابقة لهذه التعديلات** (تم التحقق بـ `git stash`): اختبارا `NetworkResilienceTest` (gzip + ping) واختبار `ActivityLogTest` (تسمية "Manufacturing Order" بعد إعادة التسمية في جولة PMO).

---

## 6. المخاطر
- **الأعمدة الكثيرة** في اليومية التحليلية ⇒ حد أقصى 6 حسابات + تمرير أفقي داخل حاوية.
- **backfill رقم القيد** على بيانات قائمة ⇒ يتم بترتيب `id` داخل الميجريشن، وباستعلام محمول (MySQL/SQLite).
- **الأداء**: اليومية تحمّل السطور بـ eager loading واحدة لكل المدة، والحساب يتم في PHP (نفس نمط `GeneralLedgerService` المحمول).
